<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Organizations\Pages;

use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Spec section 4's platform-admin cell for "Manage organization profile,
 * branding, domain": the organizer's own form (OrganizationResource::form()
 * is App\Filament\Schemas\OrganizationProfileForm) and the organizer's own
 * write, with the admin as the actor.
 *
 * Access is two checks, both Filament's: the resource's canAccess()
 * (is_platform_admin) on every mount and hydrate, then EditRecord's canEdit(),
 * which is OrganizationPolicy::update(). The second alone would admit the
 * organization's own owner, so the first is the one that keeps this page in
 * the admin panel.
 */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * Not the parent's `$record->update($data)`: the action is what writes the
     * activity entry with the admin as causer and deletes a replaced logo from
     * the branding disk once the row no longer points at it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        /** @var User $admin */
        $admin = auth()->user();

        return app(UpdateOrganizationProfile::class)->handle($record, $data, $admin);
    }
}
