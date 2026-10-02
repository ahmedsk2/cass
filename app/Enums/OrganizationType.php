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
            self::Society => __('enums.organization_type.society'),
            self::Hospital => __('enums.organization_type.hospital'),
            self::University => __('enums.organization_type.university'),
            self::Company => __('enums.organization_type.company'),
            self::Other => __('enums.organization_type.other'),
        };
    }
}
