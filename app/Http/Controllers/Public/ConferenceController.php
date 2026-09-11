<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Branding\OrganizationTheme;
use Illuminate\Contracts\View\View;

class ConferenceController extends Controller
{
    public function show(Organization $organization, Conference $conference): View
    {
        $isPreview = ! $this->isPublic($organization, $conference);

        abort_if($isPreview && ! $this->canPreview($organization), 404);

        $conference->load('tracks');

        return view('public.conference', [
            'organization' => $organization,
            'conference' => $conference,
            'theme' => OrganizationTheme::for($organization),
            'isPreview' => $isPreview,
        ]);
    }

    /**
     * Draft and archived conferences are not public, and neither is anything
     * belonging to an organization the platform has not approved (or has
     * suspended).
     */
    private function isPublic(Organization $organization, Conference $conference): bool
    {
        return $organization->isApproved() && $conference->isPubliclyVisible();
    }

    /**
     * The organizer panel refuses an unverified account on every tenant route
     * (spec section 9), and registration signs a new owner in before they
     * verify, so an unpublished conference is not readable here either until
     * the address is confirmed.
     */
    private function canPreview(Organization $organization): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasVerifiedEmail()
            && $user->roleIn($organization) !== null;
    }
}
