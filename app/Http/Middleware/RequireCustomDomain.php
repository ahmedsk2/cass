<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Conference;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the three root-level custom-domain routes. Two jobs:
 *
 * 1. Refuse when there is no resolved organization - which is what keeps
 *    /{conference} from answering on the platform host, where any unmatched
 *    single-segment path would otherwise reach it.
 * 2. Resolve {conference} THROUGH that organization and substitute the two
 *    real models into the route.
 *
 * The second is the cross-tenant guarantee. `(conference_id, slug)` is unique
 * per organization, not globally (spec section 8), so an implicit
 * {conference:slug} binding would resolve the first row in the database with
 * that slug and happily serve another society's meeting from this domain.
 * Looking it up through $organization->conferences() means no query exists
 * that could return one.
 */
class RequireCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->attributes->get(ResolveCustomDomain::ATTRIBUTE);

        if (! $organization instanceof Organization) {
            abort(404);
        }

        $route = $request->route();

        if ($route === null) {
            abort(404);
        }

        $slug = $route->parameter('conference');

        if (is_string($slug)) {
            $conference = $organization->conferences()->where('slug', $slug)->first();

            if (! $conference instanceof Conference) {
                abort(404);
            }

            // The organization is already loaded; setting the inverse relation
            // is what keeps the page's 300 ms budget (spec section 10) from
            // paying for a second query on every route() call in the view -
            // the same reason ConferenceController::show() does it.
            $conference->setRelation('organization', $organization);

            // ORDER MATTERS. ControllerDispatcher calls the method with
            // array_values($route->parameters()), and ResolvesRouteDependencies
            // skips a parameter whose class is already in that array - so a
            // plain setParameter('organization', ...) would append it AFTER
            // {conference} and call show(Conference, Organization), a TypeError
            // on every conference page on every custom domain. Forget the URI
            // parameter, then set both in the signature's order.
            $route->forgetParameter('conference');
            $route->setParameter('organization', $organization);
            $route->setParameter('conference', $conference);
        }

        return $next($request);
    }
}
