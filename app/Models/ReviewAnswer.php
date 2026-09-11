<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewQuestionType;
use Database\Factories\ReviewAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewAnswer extends Model
{
    /** @use HasFactory<ReviewAnswerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value_int' => 'integer',
            'value_bool' => 'boolean',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<ReviewQuestion, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(ReviewQuestion::class, 'review_question_id');
    }

    /**
     * The one value this answer actually carries, chosen by the question's
     * type rather than by which column happens to be non-null - a Likert answer
     * of 0 and a boolean answer of false are both real answers, and `??` over
     * four columns would swallow them.
     */
    public function value(): int|string|bool|null
    {
        return match ($this->question->type) {
            ReviewQuestionType::Likert => $this->value_int,
            ReviewQuestionType::Text => $this->value_text,
            ReviewQuestionType::Boolean => $this->value_bool,
            ReviewQuestionType::Select => $this->choice_key,
        };
    }
}
