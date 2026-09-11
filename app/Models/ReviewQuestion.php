<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewQuestionType;
use App\Exceptions\ReviewFormLocked;
use Database\Factories\ReviewQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReviewQuestion extends Model
{
    /** @use HasFactory<ReviewQuestionFactory> */
    use HasFactory;

    /**
     * `options` holds the choices of a Select question as
     * `list<array{label: string, score: int|float|null}>` (spec 5.6: a select
     * option carries an optional score). It is null for every other type.
     *
     * @var list<string>
     */
    protected $fillable = ['prompt', 'help_text', 'type', 'scale_min', 'scale_max', 'options', 'weight', 'required', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ReviewQuestionType::class,
            'options' => 'array',
            'scale_min' => 'integer',
            'scale_max' => 'integer',
            // Laravel gives a decimal column NUMERIC affinity on SQLite, which
            // returns int(1) / float(0.5), while MySQL returns "1.00" / "0.50".
            // The cast makes both a two-decimal string, and Filament's numeric
            // TextInput dehydrates a float that this normalises on the way back.
            'weight' => 'decimal:2',
            'required' => 'boolean',
            'sort' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ReviewQuestion $question): void {
            $question->ulid ??= (string) Str::ulid();
            // Spec section 3 says a question added after the lock is *appended*.
            // Filament sets no sort on create, so without this the new question
            // would land above question 1 in the form reviewers fill in - and on
            // a locked form reordering is disabled, so it could never be moved.
            $question->sort ??= ((int) static::query()->where('review_form_id', $question->review_form_id)->max('sort')) + 1;
        });

        // The lock lives in the model, not only in the policy, so it holds for
        // every path: panel, console command, and the Plan 6 legacy import.
        // Creating stays allowed - spec section 3 permits appending questions
        // to a locked form.
        static::updating(function (ReviewQuestion $question): void {
            if ($question->reviewForm?->isLocked()) {
                throw ReviewFormLocked::make();
            }
        });

        static::deleting(function (ReviewQuestion $question): void {
            if ($question->reviewForm?->isLocked()) {
                throw ReviewFormLocked::make();
            }
        });
    }

    /** @return BelongsTo<ReviewForm, $this> */
    public function reviewForm(): BelongsTo
    {
        return $this->belongsTo(ReviewForm::class);
    }

    /**
     * Spec 5.6: a Likert or boolean answer always scores, but a select answer
     * scores only through the optional `score` on a choice, so a select
     * question whose choices are all unscored contributes nothing. Plan 5
     * reads this when it averages a review.
     */
    public function isScored(): bool
    {
        if (! $this->type->isScored()) {
            return false;
        }

        if ($this->type !== ReviewQuestionType::Select) {
            return true;
        }

        foreach ($this->options ?? [] as $option) {
            if (is_array($option) && ($option['score'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }
}
