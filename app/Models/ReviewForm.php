<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReviewFormFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ReviewForm extends Model
{
    /** @use HasFactory<ReviewFormFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewForm $form): void {
            $form->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<Conference, $this> */
    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    /** @return HasMany<ReviewQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ReviewQuestion::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * Spec section 3: the form locks when the first review is submitted.
     *
     * A conditional UPDATE rather than a read-then-write, so two reviewers
     * pressing Submit in the same second cannot both believe they were first
     * and stamp two different `locked_at` values. Returns whether *this* call
     * did the locking, which is what SubmitReview logs.
     */
    public function lockIfUnlocked(): bool
    {
        $locked = static::query()
            ->whereKey($this->getKey())
            ->whereNull('locked_at')
            ->update(['locked_at' => now(), 'updated_at' => now()]) === 1;

        if ($locked) {
            $this->forceFill(['locked_at' => now()])->syncOriginal();
        }

        return $locked;
    }

    /**
     * Plan 4 sets `locked_at` when the first review on this conference is
     * submitted. Until then this is always false, and the organizer may edit,
     * reorder and delete questions freely.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
