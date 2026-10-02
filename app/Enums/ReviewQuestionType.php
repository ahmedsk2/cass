<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReviewQuestionType: string implements HasLabel
{
    case Likert = 'likert';
    case Text = 'text';
    case Boolean = 'boolean';
    case Select = 'select';

    public function getLabel(): string
    {
        return match ($this) {
            self::Likert => __('enums.review_question_type.likert'),
            self::Text => __('enums.review_question_type.text'),
            self::Boolean => __('enums.review_question_type.boolean'),
            self::Select => __('enums.review_question_type.select'),
        };
    }

    /** Text answers carry no score (spec 5.6); Plan 5 normalises the rest. */
    public function isScored(): bool
    {
        return $this !== self::Text;
    }

    public function needsScale(): bool
    {
        return $this === self::Likert;
    }

    public function needsOptions(): bool
    {
        return $this === self::Select;
    }
}
