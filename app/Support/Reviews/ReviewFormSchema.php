<?php

declare(strict_types=1);

namespace App\Support\Reviews;

use App\Enums\ReviewQuestionType;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\Rule;

/**
 * `ReviewQuestion` rows to Filament components, and the same rows to Laravel
 * validation rules, from one `match` on the question type.
 *
 * Two copies of that match - one drawing the form, one checking it - is how a
 * Likert question with bounds 1-7 comes to offer eight options on screen and
 * accept nine over the wire. The panel renders `components()`, SubmitReview
 * applies `rules()`, and neither knows how the other works.
 *
 * Keys are question **ULIDs**. They travel in a Livewire snapshot and in an
 * error bag, and spec section 3 makes the ULID the public identifier; a
 * database id in an error key is an id an organizer can read off the page.
 */
final class ReviewFormSchema
{
    /**
     * @return list<Component>
     */
    public static function components(ReviewForm $form, bool $disabled = false): array
    {
        $components = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $components[] = self::component($question)
                ->label((string) $question->prompt)
                ->helperText($question->help_text === null ? null : (string) $question->help_text)
                ->required($question->required === true)
                ->disabled($disabled)
                ->columnSpanFull();
        }

        return $components;
    }

    private static function component(ReviewQuestion $question): Radio|Select|Textarea
    {
        $key = 'answers.'.$question->ulid;

        return match ($question->type) {
            // Radio, not a Select: a 1-5 scale with its bounds visible is one
            // glance, and a reviewer answering nine of these in a row should
            // not have to open nine dropdowns.
            ReviewQuestionType::Likert => Radio::make($key)
                ->options(self::scaleOptions($question))
                ->inline()
                ->inlineLabel(false),
            ReviewQuestionType::Text => Textarea::make($key)->rows(4)->maxLength(5000),
            ReviewQuestionType::Boolean => Radio::make($key)
                ->options(['1' => __('reviewer.review.yes'), '0' => __('reviewer.review.no')])
                ->inline()
                ->inlineLabel(false),
            // Plain option arrays, never `->options(SomeEnum::class)`: these
            // are database rows, so no state cast is involved and the state is
            // an unambiguous string (fact 27).
            ReviewQuestionType::Select => Select::make($key)
                ->options($question->optionLabels())
                ->native(false),
        };
    }

    /** @return array<int, string> */
    private static function scaleOptions(ReviewQuestion $question): array
    {
        $min = (int) ($question->scale_min ?? 1);
        $max = (int) ($question->scale_max ?? 5);

        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        $options = [];

        for ($value = $min; $value <= $max; $value++) {
            $options[$value] = (string) $value;
        }

        return $options;
    }

    /**
     * @param  bool  $required  false while saving a draft: a draft is a
     *                          scratchpad and half an answer must survive a
     *                          coffee break
     * @return array<string, list<mixed>>
     */
    public static function rules(ReviewForm $form, bool $required): array
    {
        $rules = [];

        /** @var ReviewQuestion $question */
        foreach ($form->questions()->get() as $question) {
            $key = 'answers.'.$question->ulid;
            $must = $required && $question->required === true;

            $rules[$key] = match ($question->type) {
                ReviewQuestionType::Likert => [
                    $must ? 'required' : 'nullable',
                    'integer',
                    'min:'.(int) ($question->scale_min ?? 1),
                    'max:'.(int) ($question->scale_max ?? 5),
                ],
                ReviewQuestionType::Text => [$must ? 'required' : 'nullable', 'string', 'max:5000'],
                ReviewQuestionType::Boolean => [$must ? 'required' : 'nullable', 'boolean'],
                // Rule::in(), never 'in:'.implode(','). The option key IS the
                // organizer's free-text label (ReviewQuestion::optionKey()), and
                // Laravel parses an `in:` parameter list with str_getcsv
                // (Illuminate\Validation\ValidationRuleParser::parseParameters),
                // so a label such as "Oral, in person" would be split into two
                // bogus allowed values - rejecting the option the reviewer
                // actually chose, which no amount of re-picking can fix, while
                // silently admitting "Oral" and " in person" into
                // review_answers.choice_key. Rule::in() quotes each value, which
                // is exactly what it exists for.
                ReviewQuestionType::Select => [
                    $must ? 'required' : 'nullable',
                    'string',
                    Rule::in($question->optionKeys()),
                ],
            };
        }

        return $rules;
    }

    /**
     * The state a review fills the form with. A question with no answer row is
     * present and null, so Filament renders it blank rather than absent.
     *
     * @return array<string, mixed>
     */
    public static function fill(?Review $review): array
    {
        $answers = [];

        if ($review === null) {
            return ['answers' => $answers];
        }

        /** @var ReviewAnswer $answer */
        foreach ($review->answers()->with('question')->get() as $answer) {
            $question = $answer->question;

            if ($question === null) {
                continue;
            }

            $answers[$question->ulid] = match ($question->type) {
                ReviewQuestionType::Likert => $answer->value_int,
                ReviewQuestionType::Text => $answer->value_text,
                // The Radio's options are the strings '1' and '0'.
                ReviewQuestionType::Boolean => $answer->value_bool === null ? null : ($answer->value_bool ? '1' : '0'),
                ReviewQuestionType::Select => $answer->choice_key,
            };
        }

        return ['answers' => $answers];
    }
}
