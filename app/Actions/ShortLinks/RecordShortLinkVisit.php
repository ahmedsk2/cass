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
     */
    public function handle(ShortLink $shortLink, string $clientIp): void
    {
        $key = 'short-link-count:'.$shortLink->id.':'.$clientIp;

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('cass.short_link_rate_limit')))) {
            return;
        }

        RateLimiter::hit($key, 60);

        $shortLink->increment('clicks');
        $shortLink->visits()->create(['visited_at' => now()]);
    }
}
