<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrganizationType: string implements HasLabel
{
    case Society = 'society';
    case Hospital = 'hospital';
    case University = 'university';
    case Company = 'company';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Society => 'Scientific society or association',
            self::Hospital => 'Hospital or health cluster',
            self::University => 'University or college',
            self::Company => 'Company or agency',
            self::Other => 'Other',
        };
    }
}
