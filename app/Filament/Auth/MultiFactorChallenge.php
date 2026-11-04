<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\MultiFactorChallenge as BaseMultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Filament allows five second-factor attempts per account and forgets them after 60 seconds, and a wrong code is
 * only a validation error: whoever holds a password could try about 7,200 codes a day, unheard. Here the five
 * last 15 minutes, and the attempt that finds them used up writes one warning. With the panels' code window of
 * one step (three codes good at any moment), a whole day of guessing has about one chance in 700.
 *
 * The limit is per account, whichever panel's sign-in is used: every one builds its challenge with
 * MultiFactorChallenge::make(), which resolves through the container, and AppServiceProvider binds this class.
 */
class MultiFactorChallenge extends BaseMultiFactorChallenge
{
    public const int DECAY_SECONDS = 900;

    public function isRateLimited(Authenticatable $user): bool
    {
        if (! parent::isRateLimited($user)) {
            return false;
        }

        // The first refused attempt finds the count at the limit and takes it one past, so one lockout writes one
        // warning. The window, opened by the first attempt, does not move.
        $key = $this->getRateLimiterKey($user);

        if ((int) RateLimiter::attempts($key) === $this->getMaxRateLimiterAttempts()) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            Log::warning('Second-factor sign-in locked for 15 minutes after five attempts.', [
                'user_id' => $user->getAuthIdentifier(),
                'panel' => Filament::getCurrentPanel()?->getId(),
            ]);
        }

        return true;
    }

    public function hitRateLimiter(Authenticatable $user): void
    {
        RateLimiter::hit($this->getRateLimiterKey($user), self::DECAY_SECONDS);
    }
}
