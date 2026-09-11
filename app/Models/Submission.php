<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PresentationPreference;
use App\Enums\SubmissionStatus;
use App\Support\Tokens\SubmissionToken;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * Nine attributes are deliberately absent, and every one of them is written
     * with forceFill() inside an action: `conference_id` (set by the route, not
     * the form), `ulid`, `status`, `reference`, `access_token_hash`,
     * `submitted_at`, `withdrawn_at`, `last_edited_at` and `word_count` (which
     * is recomputed from `abstract` server-side, never trusted from the page).
     *
     * @var list<string>
     */
    protected $fillable = [
        'title', 'abstract', 'track_id', 'presentation_preference',
        'contact_phone', 'custom_field_values',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'presentation_preference' => PresentationPreference::class,
            'custom_field_values' => 'array',
            'word_count' => 'integer',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'last_edited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Submission $submission): void {
            $submission->ulid ??= (string) Str::ulid();
        });
    }

    /** Spec section 3: ULIDs are the public identifiers (fact 23). */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /**
     * The tenant, two hops away. A `hasOneThrough` read in the child-to-
     * grandparent direction: `conference_id` on this row points at a conference
     * whose `organization_id` points at the organization.
     *
     * It exists because the house rule is that every tenant-owned model can
     * name its organization. Nothing in Plan 3 reads it - the infolist and
     * ExportSubmissionsCsv both go through `conference` - so
     * tests/Unit/SubmissionTest.php pins it lazily and eagerly instead: an
     * inverted key pair in a six-argument hasOneThrough does not fail, it
     * quietly answers with the wrong tenant. **Filament is deliberately not
     * told to use it for tenancy** - see the comment on
     * SubmissionResource::$isScopedToTenant.
     *
     * @return HasOneThrough<Organization, Conference, $this>
     */
    public function organization(): HasOneThrough
    {
        return $this->hasOneThrough(
            Organization::class,
            Conference::class,
            'id',              // conferences.id ...
            'id',              // organizations.id ...
            'conference_id',   // ... matched from submissions.conference_id
            'organization_id', // ... and from conferences.organization_id
        );
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return HasMany<SubmissionAuthor, $this> */
    public function authors(): HasMany
    {
        return $this->hasMany(SubmissionAuthor::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<SubmissionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<ReviewAssignment, $this> */
    public function reviewAssignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class);
    }

    /** Spec 5.4 step 3: the queue is every *submitted* abstract. */
    public function isReviewable(): bool
    {
        return in_array($this->status, [SubmissionStatus::Submitted, SubmissionStatus::UnderReview], true);
    }

    /**
     * Exactly one author carries the flag - SubmitAbstract and
     * SaveSubmissionDraft both enforce that - so first() is the answer, not a
     * guess. Reads the loaded collection when there is one so the organizer
     * table does not issue a query per row.
     */
    public function correspondingAuthor(): ?SubmissionAuthor
    {
        if ($this->relationLoaded('authors')) {
            return $this->authors->firstWhere('is_corresponding', true);
        }

        return $this->authors()->where('is_corresponding', true)->first();
    }

    /**
     * One indexed read on the unique `access_token_hash`. An empty or
     * wrong-length token still hashes to something, so it simply does not
     * match - there is no separate "invalid format" branch and therefore no
     * shape of answer that tells an attacker which of the two happened.
     */
    public static function findByPlainToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()->where('access_token_hash', SubmissionToken::hash($token))->first();
    }

    /**
     * The author may still change this abstract, window permitting.
     *
     * The conference may be gone: it soft deletes, both the organizer table and
     * the edit page carry a DeleteAction, and the submissions foreign key only
     * restricts *hard* deletes - so this relation resolves to null while the
     * row survives. The public /s/{token} page calls this on every request, so
     * a missing conference closes the window instead of fatalling on a route
     * nobody is authenticated for.
     */
    public function isOpenToAuthor(): bool
    {
        return $this->status->isOpenToAuthor() && ($this->conference?->acceptsSubmissions() ?? false);
    }

    /**
     * Built from the plaintext token, which exists only for the moment
     * SaveSubmissionDraft or IssueSubmissionToken returns it. Nothing stored on
     * this row can reconstruct it.
     *
     * The `submission.status` route this resolves is registered in Task 4,
     * ahead of the component behind it, precisely because this method is called
     * from Task 5 onwards.
     */
    public function statusUrl(string $plainToken): string
    {
        return route('submission.status', ['token' => $plainToken]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Never `abstract` and never `access_token_hash`: the activity log
            // is readable by the platform admin, and neither belongs there.
            ->logOnly(['status', 'reference', 'title', 'submitted_at', 'withdrawn_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
