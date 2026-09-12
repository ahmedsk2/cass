<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Support\ClientIp;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spec section 9 asks for "login 5/min/email+IP". Filament calls
 * $this->rateLimit(5) in authenticate() (Filament\Auth\Pages\Login:70) and the
 * package keys it
 *
 *   'livewire-rate-limiter:'.sha1($component.'|'.$method.'|'.request()->ip())
 *
 * (vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27) — which
 * is neither half of what the spec asks. It omits the email, so five wrong
 * passwords from one address lock every account behind that address; and it
 * reads request()->ip() rather than App\Support\ClientIp::from(), which is the
 * only reader in this application that ignores CF-Connecting-IP. Behind
 * Cloudflare that second point is the serious one: without the real client
 * address, every login attempt on the platform shares one bucket.
 *
 * getRateLimitKey() is protected, so this subclass is the whole fix, and all
 * three panel providers point ->login() at it.
 */
class Login extends BaseLogin
{
    /**
     * The rule, as a public method, so a test can assert it without reaching
     * into a protected trait method or re-implementing sha1() by hand.
     *
     * The address is lower-cased: an attacker retrying with a capital letter
     * must not get a fresh budget, and users.email is stored lower-cased
     * anyway.
     */
    public function rateLimitKeyFor(string $email, string $ip): string
    {
        return 'livewire-rate-limiter:'.sha1(implode('|', [
            static::class,
            'authenticate',
            mb_strtolower(trim($email)),
            $ip,
        ]));
    }

    /**
     * The IP-only bucket the package gave us by accident and that this class
     * must not remove. Keying only on email+IP would hand one client five
     * attempts per minute for EVERY address - password spraying with no
     * ceiling at all, which is the attack a small platform actually sees.
     * Filament\Auth\Pages\Login:70's rateLimit(5) is the ONLY throttle on any
     * panel login route; no panel provider registers throttle: middleware and
     * no RateLimiter::for('login') exists.
     */
    public function ipRateLimitKey(string $ip): string
    {
        return 'livewire-rate-limiter:'.sha1(implode('|', [static::class, 'authenticate-ip', $ip]));
    }

    public function authenticate(): ?LoginResponse
    {
        // Checked and hit HERE, not in getRateLimitKey(): rateLimit() calls
        // getRateLimitKey() twice (itself, then hitRateLimiter()), so a hit
        // inside it would burn two attempts per submit.
        $ip = ClientIp::from(request());
        $key = $this->ipRateLimitKey($ip);
        $limit = max(1, (int) config('cass.security.login_ip_limit'));

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $this->getRateLimitedNotification(new TooManyRequestsException(
                static::class,
                'authenticate',
                $ip,
                RateLimiter::availableIn($key),
            ))?->send();

            return null;
        }

        RateLimiter::hit($key, 60);

        return parent::authenticate();
    }

    /**
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function getRateLimitKey($method, $component = null): string
    {
        // $this->data is Livewire's raw form state and is populated before
        // authenticate() calls rateLimit(), so the address is available at the
        // moment the budget is checked. An empty one still produces a valid
        // key - the IP half carries it - which is what keeps a submit with no
        // email from being unmetered.
        $email = is_string($this->data['email'] ?? null) ? $this->data['email'] : '';

        return $this->rateLimitKeyFor($email, ClientIp::from(request()));
    }
}
