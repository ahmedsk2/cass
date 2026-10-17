<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\Response;

class CustomFieldPolicy
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

    /** Null-safe for the reason TrackPolicy::view() gives. */
    public function view(User $user, CustomField $customField): bool
    {
        $organization = $customField->conference?->organization;

        return $organization !== null && $user->roleIn($organization) !== null;
    }

    public function update(User $user, CustomField $customField): bool
    {
        return $this->view($user, $customField);
    }

    /**
     * Not while an abstract of the conference, trashed included, holds an
     * answer under the key: false counts, null and '' do not. whereNotNull()
     * on a JSON path skips a JSON null on MySQL and SQLite alike.
     */
    public function delete(User $user, CustomField $customField): Response|bool
    {
        if (! $this->view($user, $customField)) {
            return false;
        }

        $path = 'custom_field_values->'.$customField->key;

        if (Submission::withTrashed()
            ->where('conference_id', $customField->conference_id)
            ->whereNotNull($path)
            ->where($path, '!=', '')
            ->exists()) {
            return Response::deny(__('organizer.custom_fields.in_use'));
        }

        return true;
    }

    /**
     * Filament checks a bulk action with the record-less `*Any` abilities and
     * treats a policy that does not define one as allowed, so all three are
     * spelled out even where the answer is always "no". deleteAny answers by
     * membership alone, for the reason TrackPolicy::deleteAny() gives. Custom
     * fields are not soft-deletable, so there is nothing to restore or
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
