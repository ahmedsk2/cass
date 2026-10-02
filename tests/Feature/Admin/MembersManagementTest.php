<?php

declare(strict_types=1);

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\RemoveMember;
use App\Enums\OrganizationRole;
use App\Filament\Admin\Resources\Organizations\OrganizationResource;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

/**
 * Spec section 4's platform-admin cell for "Manage organization members": the
 * two writes on MembersRelationManager and the one on InvitationsRelationManager,
 * each through the action class the organizer's Members page already calls,
 * with every guard that action already carries.
 */
beforeEach(function () {
    Notification::fake();

    $this->platformAdmin = User::factory()->platformAdmin()->create();
    actingAs($this->platformAdmin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create(['name' => 'Dr Owner']);
    $this->member = User::factory()->create(['name' => 'Dr Member']);
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->organization->addMember($this->member, OrganizationRole::Member);
});

function adminMembersManager(Organization $organization): Testable
{
    return livewire(MembersRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ]);
}

function adminInvitationsManager(Organization $organization): Testable
{
    return livewire(InvitationsRelationManager::class, [
        'ownerRecord' => $organization,
        'pageClass' => ViewOrganization::class,
    ]);
}

it('changes a member role and logs the platform admin as the causer', function () {
    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->member, data: ['role' => OrganizationRole::Admin->value])
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('members.notices.role_changed'));

    expect($this->member->roleIn($this->organization))->toBe(OrganizationRole::Admin);

    $entry = Activity::query()->where('description', 'organization.role_changed')->sole();

    expect($entry->causer_id)->toBe($this->platformAdmin->getKey())
        ->and($entry->subject_id)->toBe($this->organization->getKey());
});

it('hands ownership over: a new owner first, then the old one steps down', function () {
    // The support case this screen exists for. Only an owner may grant the
    // owner role, and a platform admin who is not a member acts with an
    // owner's authority - so the admin can do it without joining.
    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->member, data: ['role' => OrganizationRole::Owner->value])
        ->assertHasNoTableActionErrors();

    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->owner, data: ['role' => OrganizationRole::Member->value])
        ->assertHasNoTableActionErrors();

    expect($this->member->roleIn($this->organization))->toBe(OrganizationRole::Owner)
        ->and($this->owner->roleIn($this->organization))->toBe(OrganizationRole::Member)
        ->and($this->platformAdmin->roleIn($this->organization))->toBeNull();
});

it('removes a member, keeps their account, and logs the platform admin as the causer', function () {
    adminMembersManager($this->organization)
        ->callTableAction('remove', $this->member)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('members.notices.removed'));

    expect($this->member->roleIn($this->organization))->toBeNull()
        ->and(User::query()->whereKey($this->member->getKey())->exists())->toBeTrue();

    expect(Activity::query()->where('description', 'organization.member_removed')->sole()->causer_id)
        ->toBe($this->platformAdmin->getKey());
});

it('will not leave an organization without an owner, and says so instead of failing', function () {
    adminMembersManager($this->organization)
        ->callTableAction('changeRole', $this->owner, data: ['role' => OrganizationRole::Admin->value])
        ->assertNotified(__('members.notices.refused'));

    adminMembersManager($this->organization)
        ->callTableAction('remove', $this->owner)
        ->assertNotified(__('members.notices.refused'));

    expect($this->owner->roleIn($this->organization))->toBe(OrganizationRole::Owner)
        ->and(Activity::query()->whereIn('description', ['organization.role_changed', 'organization.member_removed'])->count())->toBe(0);
});

it('keeps every guard but the membership one for a platform admin', function () {
    $change = app(ChangeMemberRole::class);
    $remove = app(RemoveMember::class);

    // Not a member, and still allowed - including the owner-only moves.
    expect($change->blockers($this->organization, $this->member, OrganizationRole::Owner, $this->platformAdmin))->toBe([])
        ->and($remove->blockers($this->organization, $this->member, $this->platformAdmin))->toBe([])
        // The last owner is still the last owner.
        ->and($change->blockers($this->organization, $this->owner, OrganizationRole::Member, $this->platformAdmin))
        ->toBe([__('members.errors.last_owner')])
        ->and($remove->blockers($this->organization, $this->owner, $this->platformAdmin))
        ->toBe([__('members.errors.last_owner')]);

    // And a platform admin who IS a member still cannot edit their own row.
    $this->organization->addMember($this->platformAdmin, OrganizationRole::Admin);

    expect($change->blockers($this->organization, $this->platformAdmin, OrganizationRole::Owner, $this->platformAdmin))
        ->toContain(__('members.errors.own_role'))
        ->and($remove->blockers($this->organization, $this->platformAdmin, $this->platformAdmin))
        ->toContain(__('members.errors.own_membership'));
});

it('cannot reach a member of another organization through this one', function () {
    $other = Organization::factory()->approved()->create();
    $stranger = User::factory()->create(['name' => 'Somebody Else']);
    $other->addMember($stranger, OrganizationRole::Member);

    // The control: the action is there for this organization's own member,
    // so the refusal below is about the boundary and not a missing button.
    adminMembersManager($this->organization)
        ->assertTableActionVisible('changeRole', $this->member)
        ->assertCanNotSeeTableRecords([$stranger]);

    // The row actions themselves. The manager resolves a record only through
    // $organization->members(), so a foreign key is no record at all: Filament
    // refuses to mount the action before any closure runs. And
    // ChangeMemberRole::blockers() refuses a target with no role here as the
    // second wall.
    expect(fn () => adminMembersManager($this->organization)
        ->callTableAction('changeRole', $stranger, data: ['role' => OrganizationRole::Owner->value]))
        ->toThrow(ActionNotResolvableException::class)
        ->and(fn () => adminMembersManager($this->organization)->callTableAction('remove', $stranger))
        ->toThrow(ActionNotResolvableException::class);

    expect($stranger->roleIn($other))->toBe(OrganizationRole::Member)
        ->and($stranger->roleIn($this->organization))->toBeNull()
        ->and(app(ChangeMemberRole::class)->blockers($this->organization, $stranger, OrganizationRole::Owner, $this->platformAdmin))
        ->toContain(__('members.errors.not_a_member'));
});

it('withdraws a pending invitation and logs the platform admin as the causer', function () {
    $pending = OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'pending@example.org']);
    $accepted = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'joined@example.org',
        'accepted_at' => now(),
    ]);

    adminInvitationsManager($this->organization)
        ->assertTableActionHidden('revoke', $accepted)
        ->callTableAction('revoke', $pending)
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('members.notices.revoked'));

    expect($pending->fresh()?->revoked_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'organization.invitation_revoked')->sole()->causer_id)
        ->toBe($this->platformAdmin->getKey());
});

it('cannot withdraw another organization invitation through this one', function () {
    $theirs = OrganizationInvitation::factory()
        ->for(Organization::factory()->approved())
        ->create(['email' => 'theirs@example.org']);

    $ours = OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'ours@example.org']);

    adminInvitationsManager($this->organization)
        ->assertTableActionVisible('revoke', $ours)
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(fn () => adminInvitationsManager($this->organization)->callTableAction('revoke', $theirs))
        ->toThrow(ActionNotResolvableException::class);

    expect($theirs->fresh()?->revoked_at)->toBeNull();
});

it('gives an organization owner neither the page nor the actions', function () {
    $invitation = OrganizationInvitation::factory()->for($this->organization)->create();

    actingAs($this->owner)
        ->get(OrganizationResource::getUrl('view', ['record' => $this->organization], panel: 'admin'))
        ->assertForbidden();

    // Mounted directly, past the panel middleware. ChangeMemberRole and
    // RemoveMember would say yes to this owner - it is their organization - so
    // each action's authorize() is what keeps them in the admin panel.
    adminMembersManager($this->organization)
        ->assertTableActionHidden('changeRole', $this->member)
        ->assertTableActionHidden('remove', $this->member);

    adminInvitationsManager($this->organization)->assertTableActionHidden('revoke', $invitation);

    expect($this->member->roleIn($this->organization))->toBe(OrganizationRole::Member)
        ->and($invitation->fresh()?->revoked_at)->toBeNull();
});
