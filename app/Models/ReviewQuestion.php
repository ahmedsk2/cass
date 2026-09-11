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

    /**
     * The stable identifier of one Select choice, stored in
     * `review_answers.choice_key`.
     *
     * **The key is the label.** The alternatives are worse: an array index
     * moves the moment an organizer reorders the Repeater, and a synthetic id
     * would have to be back-filled onto every `options` JSON blob Plan 2
     * already wrote. The label is safe because spec section 3 locks the form
     * the moment the first review is submitted, and a locked question can
     * neither be edited nor reordered (ReviewQuestion::booted() and
     * ReviewQuestionPolicy) - so from the instant any answer exists, the label
     * cannot change. Before the lock, an edited label simply loses its score,
     * which is visible in the draft rather than silently wrong.
     *
     * This method exists so Plan 5 has one place to change if real keys are
     * ever introduced.
     *
     * @param  array<string, mixed>  $option
     */
    public static function optionKey(array $option): string
    {
        return (string) ($option['label'] ?? '');
    }

    /** @return list<string> */
    public function optionKeys(): array
    {
        return array_values(array_map(
            static fn (mixed $option): string => is_array($option) ? static::optionKey($option) : (string) $option,
            $this->options ?? [],
        ));
    }

    /**
     * Label by key, for a Radio or Select component and for the read-only
     * render of a submitted review.
     *
     * @return array<string, string>
     */
    public function optionLabels(): array
    {
        $labels = [];

        foreach ($this->optionKeys() as $key) {
            $labels[$key] = $key;
        }

        return $labels;
    }

    /** Spec 5.6: a choice carries an optional score. Plan 5 reads this. */
    public function optionScore(string $key): int|float|null
    {
        foreach ($this->options ?? [] as $option) {
            if (is_array($option) && static::optionKey($option) === $key) {
                $score = $option['score'] ?? null;

                return is_numeric($score) ? (int) $score : null;
            }
        }

        return null;
    }
}
