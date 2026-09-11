<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\ShortLinks\RecordShortLinkVisit;
use App\Http\Controllers\Controller;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use App\Support\ClientIp;
use App\Support\ShortCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShortLinkController extends Controller
{
    public function __invoke(Request $request, string $code, RecordShortLinkVisit $recordVisit): RedirectResponse
    {
        $shortLink = ShortLink::query()
            ->where('code', ShortCode::normalise($code))
            ->with('target')
            ->first();

        abort_if($shortLink === null, 404);

        $target = $shortLink->target;

        // Only conferences carry short links in v1. A draft, archived or
        // unapproved-organization conference behaves exactly like an unknown
        // code: no redirect, and no scan recorded.
        abort_unless($target instanceof Conference, 404);
        abort_unless($target->isPubliclyVisible() && $target->organization instanceof Organization && $target->organization->isApproved(), 404);

        $recordVisit->handle($shortLink, ClientIp::from($request));

        return redirect()->to($target->publicUrl(), 302);
    }
}
