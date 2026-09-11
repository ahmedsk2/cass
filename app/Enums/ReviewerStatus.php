<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ReviewerStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Removed = 'removed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Removed => 'Removed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Removed => 'gray',
        };
    }
}
