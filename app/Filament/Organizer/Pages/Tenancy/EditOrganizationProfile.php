<?php

declare(strict_types=1);

namespace App\Filament\Organizer\Pages\Tenancy;

use App\Actions\Organizations\UpdateOrganizationProfile;
use App\Filament\Schemas\OrganizationProfileForm;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * The organizer's half of spec section 4's "Manage organization profile,
 * branding, domain". The form is App\Filament\Schemas\OrganizationProfileForm,
 * which the platform admin's EditOrganization page renders too; the write is
 * App\Actions\Organizations\UpdateOrganizationProfile, which both call.
 */
class EditOrganizationProfile extends EditTenantProfile
{
    public static function canView(Model $tenant): bool
    {
        $user = auth()->user();

        return $user instanceof User && $tenant instanceof Organization
            && ($user->roleIn($tenant)?->canManageOrganization() ?? false);
    }

    public static function getLabel(): string
    {
        return __('admin.organization.profile_title');
    }

    /**
     * The record every closure in the form is handed is the panel's tenant,
     * Filament::getTenant(), not the page's own `$this->tenant` that the
     * parent's defaultForm() sets: they are the same row, and the panel's
     * instance is the one the domain actions wrote to before the form moved -
     * so it is still the one the rest of the request (and every existing test)
     * sees change.
     */
    public function form(Schema $schema): Schema
    {
        return OrganizationProfileForm::configure($schema->model(Filament::getTenant()));
    }

    /**
     * Not the parent's `$record->update($data)`: the action is what writes the
     * activity entry and deletes a replaced logo from the branding disk once
     * the row no longer points at it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        /** @var User $user */
        $user = auth()->user();

        return app(UpdateOrganizationProfile::class)->handle($record, $data, $user);
    }
}
