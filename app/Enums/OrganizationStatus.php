<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrganizationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('enums.organization_status.pending'),
            self::Approved => __('enums.organization_status.approved'),
            self::Suspended => __('enums.organization_status.suspended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Suspended => 'danger',
        };
    }
}
