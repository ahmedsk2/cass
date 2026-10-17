<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;

class TrackPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** Null-safe: another tenant's conference is scoped away under the panel, and organizations soft-delete. */
    public function view(User $user, Track $track): bool
    {
        $organization = $track->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function update(User $user, Track $track): bool
    {
        return $this->view($user, $track);
    }

    /**
     * Not while an abstract, trashed ones included, is filed under the track:
     * submissions.track_id is nullOnDelete. Membership is asked first, so the
     * reason, shown as the button's tooltip, never reaches an outsider.
     */
    public function delete(User $user, Track $track): Response|bool
    {
        if (! $this->view($user, $track)) {
            return false;
        }

        if (Submission::withTrashed()->where('track_id', $track->getKey())->exists()) {
            return Response::deny(__('organizer.tracks.in_use'));
        }

        return true;
    }

    /**
     * Filament checks a bulk action with the record-less `*Any` abilities and
     * treats a policy that does not define one as allowed, so all three are
     * spelled out even where the answer is always "no". deleteAny answers by
     * membership alone, because it is handed no row to look for abstracts in;
     * the relation manager offers no bulk delete, and one added later must
     * call authorizeIndividualRecords() so delete() is asked row by row.
     * Tracks are not soft-deletable, so there is nothing to restore or
     * force-delete.
     */
    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
