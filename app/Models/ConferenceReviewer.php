<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewerStatus;
use Database\Factories\ConferenceReviewerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A first-class model rather than a Pivot, even though it sits between
 * `conferences` and `users`: it carries a ULID, a status, an affiliation and a
 * policy of its own, it is the row an organizer acts on in the Reviewers page,
 * and making it a Pivot would mean `Conference::activeReviewers()` returning
 * User models whose interesting attributes all live behind `->pivot`.
 */
class ConferenceReviewer extends Model
{
    /** @use HasFactory<ConferenceReviewerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewerStatus::class,
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ConferenceReviewer $reviewer): void {
            $reviewer->ulid ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isActive(): bool
    {
        return $this->status === ReviewerStatus::Active;
    }

    /**
     * @param  Builder<ConferenceReviewer>  $query
     * @return Builder<ConferenceReviewer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReviewerStatus::Active->value);
    }
}
