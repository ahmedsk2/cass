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
            self::Likert => 'Rating scale',
            self::Text => 'Free text comment',
            self::Boolean => 'Yes / no',
            self::Select => 'Choose one from a list',
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
