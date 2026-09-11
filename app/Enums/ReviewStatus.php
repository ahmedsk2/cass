<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReviewStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'success',
        };
    }

    /** Counted as done by every progress number in this plan. */
    public function countsAsComplete(): bool
    {
        return $this === self::Submitted;
    }
}
