<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationRegistered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RegisterOrganization
{
    /**
     * @param  array{name: string, email: string, password: string, organization_name: string, organization_type: string, country: string, website: ?string, purpose: string}  $data
     */
    public function handle(array $data): User
    {
        /** @var array{0: User, 1: Organization} $created */
        $created = DB::transaction(function () use ($data): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $organization = Organization::query()->create([
                'name' => $data['organization_name'],
                'type' => OrganizationType::from($data['organization_type']),
                'country' => $data['country'],
                'website' => $data['website'] ?: null,
                'contact_email' => $data['email'],
                'purpose' => $data['purpose'],
            ]);

            $organization->addMember($user, OrganizationRole::Owner);

            return [$user, $organization];
        });

        [$user, $organization] = $created;

        $user->sendEmailVerificationNotification();

        Notification::send(
            User::query()->where('is_platform_admin', true)->get(),
            new OrganizationRegistered($organization, $user),
        );

        return $user;
    }
}
