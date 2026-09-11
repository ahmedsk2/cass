<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewStatus;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Answers are written through ReviewAnswer, and every column here is a
     * transition (SaveReviewDraft, SubmitReview, ReopenReview) rather than a
     * field on a form.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            // Written by ComputeSubmissionScore for every review, draft or
            // submitted; the submission aggregate reads only the submitted
            // ones. Same decimal:2 reasoning as submissions.score.
            'score' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Review $review): void {
            $review->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /** @return BelongsTo<ReviewForm, $this> */
    public function reviewForm(): BelongsTo
    {
        return $this->belongsTo(ReviewForm::class);
    }

    /** @return HasMany<ReviewAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ReviewAnswer::class);
    }

    public function isSubmitted(): bool
    {
        return $this->status === ReviewStatus::Submitted;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // Never the answers: the activity log is readable by the platform
            // admin and a review's content is not audit data.
            ->logOnly(['status', 'submitted_at', 'reopened_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
