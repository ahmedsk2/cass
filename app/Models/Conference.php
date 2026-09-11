<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionWindow;
use App\Support\Submissions\ReferencePrefix;
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
        'reference_prefix',
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
            'reviewer_reminded_at' => 'datetime',
            'published_at' => 'datetime',
            'blind_review' => 'boolean',
            'reviewers_per_submission' => 'integer',
            'word_limit' => 'integer',
            'max_files' => 'integer',
            'allowed_file_types' => 'array',
            'presentation_types' => 'array',
            'submission_counter' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Conference $conference): void {
            $conference->ulid ??= (string) Str::ulid();
            $conference->slug ??= static::uniqueSlug((int) $conference->organization_id, (string) $conference->name);
            // Derived once, on create, so an organizer who renames a
            // conference after the first abstract arrives does not silently
            // change the prefix printed on every reference already issued.
            // The form lets them change it by hand while the conference is
            // still a draft.
            $conference->reference_prefix ??= ReferencePrefix::derive(
                (string) $conference->name,
                $conference->starts_at,
            );
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

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /** @return HasMany<EmailTemplate, $this> */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }

    /** @return HasMany<ReviewerInvitation, $this> */
    public function reviewerInvitations(): HasMany
    {
        return $this->hasMany(ReviewerInvitation::class);
    }

    /** @return HasMany<ConferenceReviewer, $this> */
    public function reviewers(): HasMany
    {
        return $this->hasMany(ConferenceReviewer::class);
    }

    /**
     * The pool. Every reviewer query in Plan 4 starts here, so "active" has one
     * definition.
     *
     * @return HasMany<ConferenceReviewer, $this>
     */
    public function activeReviewers(): HasMany
    {
        return $this->reviewers()->where('status', ReviewerStatus::Active->value);
    }

    /** @return HasMany<ReviewerReminder, $this> */
    public function reviewerReminders(): HasMany
    {
        return $this->hasMany(ReviewerReminder::class);
    }

    /**
     * Spec 5.4 step 4: "authors hidden when blind". Blind review is a rule
     * about *reviewers*, not about the conference as a whole - spec section 4
     * gives every organization member "View submissions and files" with no
     * caveat, and somebody has to be able to answer an author's email. So this
     * asks who is looking.
     *
     * One method, called by the reviewer's queue, the review page, the file
     * naming and their tests, so "blind" cannot come to mean four things.
     */
    public function hidesAuthorsFrom(?User $user): bool
    {
        if ($this->blind_review !== true) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        if ($user->is_platform_admin === true) {
            return false;
        }

        return $user->roleIn($this->organization) === null;
    }

    /** Timestamps are stored UTC; organizers and reviewers read them locally. */
    public function reviewDeadlineInConferenceTimezone(): ?CarbonInterface
    {
        return $this->review_deadline?->copy()->setTimezone($this->timezone);
    }

    /** The window in which a reviewer may still change a submitted review. */
    public function reviewWindowIsOpen(): bool
    {
        return $this->review_deadline === null || $this->review_deadline->isFuture();
    }

    /** Conferences a reviewer may open at all (spec 5.4 step 3). */
    public function isOpenToReviewers(): bool
    {
        return in_array($this->status, [ConferenceStatus::Reviewing, ConferenceStatus::Decided], true);
    }

    /**
     * READING a conference is open in Reviewing and in Decided - a reviewer who
     * wants to see what they said about an abstract after the committee has
     * decided should be able to. WRITING a review is open in Reviewing only:
     * once the decisions are out, a new answer would change the evidence the
     * committee was shown after the fact.
     *
     * Kept separate from isOpenToReviewers() on purpose. Every read path
     * (ReviewerScope::constrain, the queue, the dashboard) asks that one; every
     * write path (SaveReviewDraft, SubmitReview, ReopenReview, the review form's
     * read-only rule) asks this one.
     */
    public function acceptsReviewWrites(): bool
    {
        return $this->status === ConferenceStatus::Reviewing;
    }

    /**
     * Rows created before the reference columns existed have none, and the
     * organizer may have cleared the field; derive rather than return null, so
     * AllocateReference never has to handle a prefix-less conference.
     */
    public function referencePrefix(): string
    {
        return $this->reference_prefix !== null && $this->reference_prefix !== ''
            ? $this->reference_prefix
            : ReferencePrefix::derive((string) $this->name, $this->starts_at);
    }

    /**
     * The extensions an author may attach, never empty - the same
     * derive-rather-than-return-null rule referencePrefix() follows.
     *
     * `?? ['pdf']` at the call sites caught a null column and not an empty
     * array, and the two readers disagreed about the empty one:
     * SubmissionForm::uploadRules() built the rule string `extensions:` with no
     * parameters, which Laravel answers with an InvalidArgumentException - a
     * 500 on the public form the moment an author picks a file - while
     * StoreSubmissionFile refused every extension with a sentence naming none.
     * An empty list is the same "nothing configured" state as null, so both
     * read it here and both get the same answer.
     *
     * @return list<string>
     */
    public function allowedFileTypes(): array
    {
        /** @var list<string> $types */
        $types = array_values(array_map('strtolower', (array) ($this->allowed_file_types ?? [])));

        return $types === [] ? ['pdf'] : $types;
    }

    /**
     * One grouped query for the four numbers the conference view prints.
     *
     * @return array{total: int, draft: int, submitted: int, withdrawn: int}
     */
    public function submissionCounts(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = $this->submissions()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'total' => array_sum($byStatus),
            'draft' => (int) ($byStatus[SubmissionStatus::Draft->value] ?? 0),
            'submitted' => (int) ($byStatus[SubmissionStatus::Submitted->value] ?? 0),
            'withdrawn' => (int) ($byStatus[SubmissionStatus::Withdrawn->value] ?? 0),
        ];
    }

    /**
     * Spec 5.5: "a coverage summary (submissions with fewer than N reviewers)".
     *
     * One grouped query plus one count, rather than a loop over submissions:
     * spec section 10 budgets for 500 abstracts and this renders on every visit
     * to the assignments page.
     *
     * @return array{target: int, submissions: int, covered: int, under: int, unassigned: int}
     */
    public function assignmentCoverage(): array
    {
        $target = max(1, (int) $this->reviewers_per_submission);

        /** @var array<int, int> $counts assignment count keyed by submission id */
        $counts = $this->submissions()
            ->whereIn('status', [SubmissionStatus::Submitted->value, SubmissionStatus::UnderReview->value])
            ->withCount('reviewAssignments')
            ->pluck('review_assignments_count', 'id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $covered = 0;
        $unassigned = 0;

        foreach ($counts as $count) {
            if ($count >= $target) {
                $covered++;
            }

            if ($count === 0) {
                $unassigned++;
            }
        }

        $submissions = count($counts);

        return [
            'target' => $target,
            'submissions' => $submissions,
            'covered' => $covered,
            'under' => $submissions - $covered,
            'unassigned' => $unassigned,
        ];
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
            ->logOnly(['name', 'slug', 'status', 'submission_opens_at', 'submission_deadline', 'review_deadline', 'published_at', 'reference_prefix'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
