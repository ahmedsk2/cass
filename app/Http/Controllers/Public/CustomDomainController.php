<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCustomDomain;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomDomainController extends Controller
{
    /**
     * GET / on a verified custom domain.
     *
     * One publicly visible conference -> go there. Zero or several -> 404.
     * A listing page is a feature spec 5.8 does not ask for, and "the newest"
     * would silently send visitors to the wrong meeting in exactly the week a
     * society runs two calls at once.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $organization = $request->attributes->get(ResolveCustomDomain::ATTRIBUTE);

        abort_unless($organization instanceof Organization, 404);

        $conferences = $organization->conferences()
            ->get()
            ->filter(static fn (Conference $conference): bool => $conference->isPubliclyVisible());

        abort_unless($conferences->count() === 1 && $organization->isApproved(), 404);

        /** @var Conference $conference */
        $conference = $conferences->first();

        return redirect()->to($conference->publicUrl());
    }
}
