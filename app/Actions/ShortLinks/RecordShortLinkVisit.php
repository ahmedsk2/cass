<?php

declare(strict_types=1);

namespace App\Actions\ShortLinks;

use App\Models\ShortLink;
use Illuminate\Support\Facades\RateLimiter;

class RecordShortLinkVisit
{
    /**
     * One counter bump plus one timestamp row. The counter is what the panel
     * shows at a glance; the rows are what the 30-day breakdown reads.
     *
     * The cap lives here, not on the route: spec 5.7 says /q/{code} redirects
     * and counts every visit, so a route-level throttle would answer 429 to
     * the 61st person scanning a poster in a lecture hall behind one NAT
     * address - exactly the moment the QR code exists for. Capping the count
     * still stops a script inflating the total, and the visitor always reaches
     * the page.
     *
     * There are two ceilings because the client IP comes from a header the
     * client controls (CF-Connecting-IP, see App\Support\ClientIp): with only
     * the per-client one, a single host mints a fresh budget per request and
     * writes an unbounded number of rows into short_link_visits, which the
     * organizer sharing page then has to read back. The second key drops the
     * IP, so a link's rows can only grow at a fixed rate however many
     * addresses are claimed.
     */
    public function handle(ShortLink $shortLink, string $clientIp): void
    {
        $perClient = 'short-link-count:'.$shortLink->id.':'.$clientIp;
        $perLink = 'short-link-count:'.$shortLink->id;

        if (RateLimiter::tooManyAttempts($perClient, max(1, (int) config('cass.short_link_rate_limit')))) {
            return;
        }

        if (RateLimiter::tooManyAttempts($perLink, max(1, (int) config('cass.short_link_rate_limit_per_link')))) {
            return;
        }

        RateLimiter::hit($perClient, 60);
        RateLimiter::hit($perLink, 60);

        $shortLink->increment('clicks');
        $shortLink->visits()->create(['visited_at' => now()]);
    }
}
