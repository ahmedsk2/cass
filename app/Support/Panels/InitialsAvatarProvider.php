<?php

declare(strict_types=1);

namespace App\Support\Panels;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class InitialsAvatarProvider implements AvatarProvider
{
    // Widened from the contract's Model to match UiAvatarsProvider: Filament
    // passes the Authenticatable for a user and a Model for a tenant.
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()->explode(' ')
            ->map(fn (string $segment): string => mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment) ?? '', 0, 1))
            ->filter()->take(2)->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#18181b"/>'
            .'<text x="32" y="32" fill="#ffffff" font-family="sans-serif" font-size="26" text-anchor="middle" dominant-baseline="central">'
            .e(mb_strtoupper($initials)).'</text></svg>';

        // data: is already in img-src.
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
