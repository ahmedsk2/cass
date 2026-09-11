<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Derived from `accepted_at`, `revoked_at` and `expires_at`, never stored.
 * `expired` is a function of the clock, so a column would be wrong for exactly
 * as long as nothing ran to correct it.
 */
enum InvitationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Invited',
            self::Accepted => 'Accepted',
            self::Expired => 'Expired',
            self::Revoked => 'Withdrawn',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Accepted => 'success',
            self::Expired => 'warning',
            self::Revoked => 'gray',
        };
    }

    /** The only state in which /invite/{token} may still be accepted. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
