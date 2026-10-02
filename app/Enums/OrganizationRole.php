<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrganizationRole: string implements HasLabel
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => __('enums.organization_role.owner'),
            self::Admin => __('enums.organization_role.admin'),
            self::Member => __('enums.organization_role.member'),
        };
    }

    public function canManageOrganization(): bool
    {
        return $this !== self::Member;
    }
}
