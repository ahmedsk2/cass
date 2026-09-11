<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionWindow;
use Carbon\CarbonInterface;
use Database\Factories\ConferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Conference extends Model
{
    /** @use HasFactory<ConferenceFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    /**
     * `organization_id`, `ulid`, `slug`, `status` and `published_at` are
     * deliberately absent: the tenant comes from the panel, the slug is
     * derived, and the status only moves through the action classes with
     * forceFill(). Model::preventSilentlyDiscardingAttributes() is on
     * outside production, so a form field that is not listed here fails
     * loudly instead of being dropped.
     *
     * @var list<string>
     */
    /**
     * Column-level defaults for the JSON settings: MySQL cannot default a JSON
     * column, and callers other than the panel form (imports, seeds) must not
     * have to know these values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'allowed_file_types' => '["pdf"]',
        'presentation_types' => '["oral","poster","either"]',
    ];

    protected $fillable = [
        'name', 'short_description', 'description', 'venue', 'city', 'country',
        'starts_at', 'ends_at', 'timezone',
        'submission_opens_at', 'submission_deadline', 'review_deadline',
        'review_mode', 'blind_review', 'reviewers_per_submission',
        'word_limit', 'max_files', 'allowed_file_types', 'presentation_types', 'terms',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConferenceStatus::class,
            'review_mode' => ReviewMode::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'submission_opens_at' => 'datetime',
            'submission_deadline' => 'datetime',
            'review_deadline' => 'datetime',
            'published_at' => 'datetime',
            'blind_review' => 'boolean',
            'reviewers_per_submission' => 'integer',
            'word_limit' => 'integer',
            'max_files' => 'integer',
            'allowed_file_types' => 'array',
            'presentation_types' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conference $conference): void {
            $conference->ulid ??= (string) Str::ulid();
            $conference->slug ??= static::uniqueSlug((int) $conference->organization_id, (string) $conference->name);
        });
    }

    /**
     * Scoped to one organization, and blocked by trashed rows as well, so a
     * published URL is never recycled.
     */
    public static function uniqueSlug(int $organizationId, string $name): string
    {
        $base = Str::slug($name) ?: 'conference';
        $slug = $base;
        $n = 2;

        while (static::withTrashed()->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * Spec section 3 makes the ULID the public identifier, and Filament builds
     * every record URL from getRouteKey() while resolving it with the
     * resource's own key name, so these two must agree (fact 16). The public
     * page is the one place that wants the slug, and routes/web.php asks for
     * it explicitly with `{conference:slug}`.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<CustomField, $this> */
    public function customFields(): HasMany
    {
        return $this->hasMany(CustomField::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<ReviewForm, $this> */
    public function reviewForms(): HasMany
    {
        return $this->hasMany(ReviewForm::class);
    }

    /** A conference has exactly one active review form (spec section 3). */
    /** @return HasOne<ReviewForm, $this> */
    public function reviewForm(): HasOne
    {
        return $this->hasOne(ReviewForm::class)->where('is_active', true);
    }

    /**
     * Read-only path to every question of every form on this conference. It
     * exists so Filament's relation-manager authorization can resolve the
     * related model class; writes always go through the active form.
     *
     * @return HasManyThrough<ReviewQuestion, ReviewForm, $this>
     */
    public function reviewQuestions(): HasManyThrough
    {
        return $this->hasManyThrough(ReviewQuestion::class, ReviewForm::class);
    }

    /** @return MorphOne<ShortLink, $this> */
    public function shortLink(): MorphOne
    {
        return $this->morphOne(ShortLink::class, 'target');
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status->isPublic();
    }

    public function submissionWindow(): SubmissionWindow
    {
        if ($this->submission_opens_at === null || $this->submission_deadline === null) {
            return SubmissionWindow::NotConfigured;
        }

        if (! $this->status->acceptsSubmissions()) {
            return SubmissionWindow::Closed;
        }

        return match (true) {
            $this->submission_opens_at->isFuture() => SubmissionWindow::Upcoming,
            $this->submission_deadline->isPast() => SubmissionWindow::Closed,
            default => SubmissionWindow::Open,
        };
    }

    public function acceptsSubmissions(): bool
    {
        return $this->submissionWindow() === SubmissionWindow::Open;
    }

    /** Timestamps are stored UTC; organizers and authors read them locally. */
    public function deadlineInConferenceTimezone(): ?CarbonInterface
    {
        return $this->submission_deadline?->copy()->setTimezone($this->timezone);
    }

    public function opensAtInConferenceTimezone(): ?CarbonInterface
    {
        return $this->submission_opens_at?->copy()->setTimezone($this->timezone);
    }

    public function publicUrl(): string
    {
        return route('conference.show', [
            'organization' => $this->organization,
            'conference' => $this,
        ]);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'slug', 'status', 'submission_opens_at', 'submission_deadline', 'review_deadline', 'published_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
