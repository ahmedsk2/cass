<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile, in the twenty lines it actually takes. No package: a
 * wrapper for this would bring a middleware and a Blade component, and the
 * widget here lives inside a Livewire form whose own action does the
 * verification.
 *
 * Spec 5.3: "Cloudflare Turnstile when keys are configured". With no keys it is
 * transparently absent - no widget, no HTTP call, no refusal - so local
 * development and the test suite are unaffected and a deployment turns it on by
 * setting two environment variables.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public static function isConfigured(): bool
    {
        return filled(config('cass.turnstile.site_key')) && filled(config('cass.turnstile.secret_key'));
    }

    /**
     * The key the form renders the widget with, and null whenever the widget
     * must not be rendered at all.
     *
     * It answers for *both* keys, not just its own. A deploy that sets only
     * TURNSTILE_SITE_KEY - the easy half, because the public key is the one that
     * gets pasted around - would otherwise render the challenge while verify()
     * returns true on its first line, because isConfigured() is false. The
     * author solves a puzzle nobody checks and the operator reads the widget on
     * the page as proof that bot protection is on. Half-configured is off.
     */
    public static function siteKey(): ?string
    {
        if (! self::isConfigured()) {
            return null;
        }

        $key = config('cass.turnstile.site_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * True when the request may proceed.
     *
     * The failure modes, and why each answers the way it does:
     *
     * - **Not configured** -> true. There is nothing to verify.
     * - **No token** -> false. The widget is on the page; a submit without one
     *   is a client that did not run it.
     * - **`success: false`** -> false. Cloudflare looked and said no.
     * - **A non-2xx answer, or no answer at all** -> **true, and logged**. This
     *   is the deliberate fail-open. An outage at Cloudflare would otherwise
     *   refuse every abstract in the last hour before a deadline, which is
     *   exactly when it would cost the most, and the honeypot, the minimum fill
     *   time and the per-IP throttle are all still running underneath. The log
     *   line is what makes the outage visible rather than inferred from a quiet
     *   submission count.
     */
    public static function verify(?string $token, string $clientIp): bool
    {
        if (! self::isConfigured()) {
            return true;
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->connectTimeout(3)
                ->post(self::VERIFY_URL, [
                    'secret' => (string) config('cass.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $clientIp,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Turnstile verification could not reach Cloudflare; allowing the request.', [
                'message' => $exception->getMessage(),
            ]);

            return true;
        }

        if ($response->serverError()) {
            // Cloudflare answered, badly. Treated as a refusal rather than an
            // outage: an answer that parses to nothing is not the same as no
            // answer, and this is the shape a misconfigured secret produces.
            return false;
        }

        return $response->json('success') === true;
    }
}
