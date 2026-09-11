<?php

declare(strict_types=1);

use App\Actions\Organizations\ChangeMemberRole;
use App\Actions\Organizations\InviteMember;
use App\Actions\Organizations\RemoveMember;
use App\Actions\Submissions\SubmitAbstract;
use App\Enums\OrganizationRole;
use App\Exceptions\MemberChangeRefused;
use App\Filament\Organizer\Pages\Members;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\ReviewerInvitation;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Support\Invitations\InvitationLookup;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->owner = User::factory()->create(['name' => 'Dr Owner', 'email' => 'owner@example.org']);
    $this->admin = User::factory()->create(['name' => 'Dr Admin', 'email' => 'admin@example.org']);
    $this->member = User::factory()->create(['name' => 'Dr Member', 'email' => 'member@example.org']);
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    $this->organization->addMember($this->admin, OrganizationRole::Admin);
    $this->organization->addMember($this->member, OrganizationRole::Member);

    actingAs($this->owner);
    bootOrganizerPanel($this->organization);
});

it('lists every member with their role and the pending invitations', function () {
    OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'invited@example.org']);

    livewire(Members::class)
        ->assertOk()
        ->assertSee('Dr Owner')
        ->assertSee('Dr Admin')
        ->assertSee('Dr Member')
        ->assertSee('invited@example.org')
        ->assertSee(OrganizationRole::Owner->getLabel())
        ->assertSee(__('members.state.invited'));
});

it('does not list another organization members or invitations', function () {
    [$theirUser, $theirInvitation] = withoutTenant(function (): array {
        $other = Organization::factory()->approved()->create();
        $user = User::factory()->create(['name' => 'Somebody Else', 'email' => 'else@example.org']);
        $other->addMember($user, OrganizationRole::Owner);

        return [$user, OrganizationInvitation::factory()->for($other)->create(['email' => 'their-invite@example.org'])];
    });

    livewire(Members::class)
        ->assertDontSee('Somebody Else')
        ->assertDontSee('their-invite@example.org');

    expect($theirUser->roleIn($this->organization))->toBeNull()
        ->and($theirInvitation->organization_id)->not->toBe($this->organization->id);
});

it('invites by email and role, queues the notification and logs it', function () {
    livewire(Members::class)
        ->callAction('invite', data: ['email' => 'New@Example.ORG', 'role' => OrganizationRole::Admin->value])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $invitation = OrganizationInvitation::query()->where('email', 'new@example.org')->firstOrFail();

    expect($invitation->role)->toBe(OrganizationRole::Admin)
        ->and($invitation->invited_by)->toBe($this->owner->id)
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->expires_at->toDateString())->toBe(now()->addDays(14)->toDateString());

    Notification::assertSentOnDemand(
        MemberInvitation::class,
        // The token carried to the mailbox has to be the plaintext that hashes
        // to the row just written. Asserting only the address passed on a stale
        // token, on the stored hash, or on a second freshly generated one - that
        // is, in exactly the world where no invitation is ever acceptable.
        fn (MemberInvitation $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'new@example.org'
            && $notification->organization->is($this->organization)
            && app(InvitationLookup::class)->find($notification->token)?->is($invitation) === true,
    );

    expect(Activity::query()->where('description', 'organization.member_invited')->count())->toBe(1);
});

it('re-invites by refreshing the live row instead of creating a second link', function () {
    livewire(Members::class)->callAction('invite', data: ['email' => 'new@example.org', 'role' => OrganizationRole::Member->value]);
    $first = OrganizationInvitation::query()->where('email', 'new@example.org')->firstOrFail();

    livewire(Members::class)->callAction('invite', data: ['email' => 'new@example.org', 'role' => OrganizationRole::Admin->value]);

    // One row, one live link. Two rows would mean two working links in one
    // inbox and no way to say which "Revoke" revoked.
    expect(OrganizationInvitation::query()->where('email', 'new@example.org')->count())->toBe(1)
        ->and($first->fresh()?->role)->toBe(OrganizationRole::Admin)
        ->and($first->fresh()?->token_hash)->not->toBe($first->token_hash);
});

it('refuses to send once the hourly invitation limit trips, and touches nothing', function () {
    // Anybody can self-register an organization at /register and canAccessTenant
    // does not wait for approval, so the invite action is reachable by anyone
    // with an email address. Without a limiter it is an unmetered relay for
    // organization-signed mail carrying a display name and an organization name
    // of the sender's choosing. InviteReviewer already meters the same actor on
    // the same bucket: it is one person's mail budget, not one table's.
    $limit = (int) config('cass.invitations.send_rate_limit');
    RateLimiter::clear('invitation-send:'.$this->owner->getKey());

    // A live invitation whose address is the one re-invited below. InviteMember
    // re-invites by refreshing this very row, so a limiter consulted AFTER the
    // write would kill a link that was emailed an hour ago for a message that
    // then never leaves.
    $live = OrganizationInvitation::factory()->for($this->organization)
        ->create(['email' => 'new@example.org', 'invited_by' => $this->owner->id]);
    $hashBefore = (string) $live->token_hash;

    foreach (range(1, $limit) as $ignored) {
        RateLimiter::hit('invitation-send:'.$this->owner->getKey(), 3600);
    }

    livewire(Members::class)
        ->callAction('invite', data: ['email' => 'new@example.org', 'role' => OrganizationRole::Member->value])
        ->assertNotified();

    expect(fn () => app(InviteMember::class)->handle(
        $this->organization, 'other@example.org', OrganizationRole::Member, $this->owner,
    ))->toThrow(MemberChangeRefused::class, __('members.errors.send_limit'));

    expect(OrganizationInvitation::query()->count())->toBe(1)
        ->and((string) $live->fresh()?->token_hash)->toBe($hashBefore);

    Notification::assertNothingSent();
});

it('refuses to invite somebody who is already a member', function () {
    livewire(Members::class)
        ->callAction('invite', data: ['email' => 'member@example.org', 'role' => OrganizationRole::Admin->value])
        ->assertNotified();

    expect(OrganizationInvitation::query()->count())->toBe(0);
});

it('lets only an owner offer the owner role', function () {
    // assertSee() after mountAction() would read the component HTML from BEFORE
    // the action mounted (Livewire's SubsequentRender forwards the previous
    // html; the modal is in effects.partials), so it would pass here for the
    // wrong reason - the members table already prints the word "Owner" on the
    // owner's own row - and prove nothing about the Select's options.
    // assertMountedActionModalSee/DontSee read the partial Filament actually
    // rendered (vendor/filament/actions/src/Testing/TestsActions.php:512, :533);
    // tests/Feature/Organizer/ConferenceEmailTemplatesTest.php records the same
    // trap for this repo.
    livewire(Members::class)
        ->mountAction('invite')
        ->assertMountedActionModalSee(OrganizationRole::Owner->getLabel());

    actingAs($this->admin);

    // The option is not on an admin's list at all - roleOptions() filters it ...
    livewire(Members::class)
        ->mountAction('invite')
        ->assertMountedActionModalDontSee(OrganizationRole::Owner->getLabel());

    // ... and InviteMember::blockers() refuses it even if the value is forged.
    expect(app(InviteMember::class)->blockers(
        $this->organization, 'x@example.org', OrganizationRole::Owner, $this->admin,
    ))->not->toBe([]);
});

it('resends an invitation with a new token and revokes one', function () {
    $invitation = OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'invited@example.org']);
    $original = $invitation->token_hash;

    livewire(Members::class)
        ->callTableAction('resend', 'invitation:'.$invitation->getKey())
        ->assertHasNoTableActionErrors();

    expect($invitation->fresh()?->token_hash)->not->toBe($original);

    livewire(Members::class)
        ->callTableAction('revoke', 'invitation:'.$invitation->getKey())
        ->assertHasNoTableActionErrors();

    expect($invitation->fresh()?->revoked_at)->not->toBeNull();

    // Revoked, so it leaves the list - but the row survives, so the person
    // holding the emailed link gets a sentence rather than a 404 (Task 2).
    livewire(Members::class)->assertDontSee('invited@example.org');
});

it('changes a role and writes an activity entry', function () {
    livewire(Members::class)
        ->callTableAction('changeRole', 'member:'.$this->member->getKey(), data: ['role' => OrganizationRole::Admin->value])
        ->assertHasNoTableActionErrors();

    expect($this->member->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Admin)
        ->and(Activity::query()->where('description', 'organization.role_changed')->count())->toBe(1);
});

it('refuses to change your own role, from the page and from the action', function () {
    livewire(Members::class)
        ->assertTableActionHidden('changeRole', 'member:'.$this->owner->getKey())
        ->assertTableActionHidden('remove', 'member:'.$this->owner->getKey());

    expect(fn () => app(ChangeMemberRole::class)->handle($this->organization, $this->owner, OrganizationRole::Member, $this->owner))
        ->toThrow(MemberChangeRefused::class);
});

it('keeps at least one owner', function () {
    // The only owner: demoting them would leave the organization with nobody
    // who can invite, change a role, or edit the profile.
    $this->organization->members()->updateExistingPivot($this->admin->getKey(), ['role' => OrganizationRole::Member->value]);

    expect(app(ChangeMemberRole::class)->blockers($this->organization, $this->owner, OrganizationRole::Admin, $this->owner))
        ->not->toBe([])
        ->and(app(RemoveMember::class)->blockers($this->organization, $this->owner, $this->admin))
        ->not->toBe([]);

    // With a second owner, the first may be demoted.
    $this->organization->members()->updateExistingPivot($this->admin->getKey(), ['role' => OrganizationRole::Owner->value]);

    actingAs($this->admin);
    app(ChangeMemberRole::class)->handle($this->organization, $this->owner, OrganizationRole::Member, $this->admin);

    expect($this->owner->fresh()?->roleIn($this->organization))->toBe(OrganizationRole::Member);
});

it('stops an admin touching an owner', function () {
    actingAs($this->admin);
    bootOrganizerPanel($this->organization);

    expect(app(ChangeMemberRole::class)->blockers($this->organization, $this->owner, OrganizationRole::Member, $this->admin))->not->toBe([])
        ->and(app(RemoveMember::class)->blockers($this->organization, $this->owner, $this->admin))->not->toBe([])
        // ... but may still manage a plain member.
        ->and(app(RemoveMember::class)->blockers($this->organization, $this->member, $this->admin))->toBe([]);
});

it('removes a member and leaves their user account alone', function () {
    livewire(Members::class)
        ->callTableAction('remove', 'member:'.$this->member->getKey())
        ->assertHasNoTableActionErrors();

    expect($this->member->fresh()?->roleIn($this->organization))->toBeNull()
        ->and(User::query()->whereKey($this->member->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'organization.member_removed')->count())->toBe(1);
});

it('lets a plain member open the page and toggle only their own notifications', function () {
    OrganizationInvitation::factory()->for($this->organization)
        ->create(['email' => 'pending@example.org', 'role' => OrganizationRole::Owner]);

    actingAs($this->member);
    bootOrganizerPanel($this->organization);

    livewire(Members::class)
        ->assertOk()
        // The page is shared with a plain member so they can reach the line
        // below; the invitation rows are not. Who has been invited and at what
        // privilege is what spec section 4 reserves for an owner or an admin,
        // and what OrganizationInvitationPolicy::viewAny() refuses them.
        ->assertSee('Dr Member')
        ->assertDontSee('pending@example.org')
        ->assertActionHidden('invite')
        ->assertTableActionHidden('changeRole', 'member:'.$this->owner->getKey())
        ->assertTableActionHidden('notifications', 'member:'.$this->owner->getKey())
        ->assertTableActionVisible('notifications', 'member:'.$this->member->getKey())
        ->callTableAction('notifications', 'member:'.$this->member->getKey())
        ->assertHasNoTableActionErrors();

    // Default is true (Plan 1's column default), so one click turns it off -
    // and SubmitAbstract::notifiableMembers() stops reaching them.
    expect((bool) $this->member->fresh()?->organizations()->whereKey($this->organization)->first()?->pivot->notify_on_submission)
        ->toBeFalse()
        ->and(SubmitAbstract::notifiableMembers(
            Conference::factory()->for($this->organization)->published()->create(),
        )->pluck('id')->all())->not->toContain($this->member->id);
});

it('refuses a role change, a removal and a revoke across the tenant boundary', function () {
    // The listing case above stops at assertDontSee. These are the five row
    // actions themselves: Members::memberUser() and Members::invitation() scope
    // by tenant, and ChangeMemberRole/RemoveMember refuse a target with no role
    // here - and neither guard was exercised by anything.
    [$stranger, $theirInvitation] = withoutTenant(function (): array {
        $other = Organization::factory()->approved()->create();
        $user = User::factory()->create(['name' => 'Somebody Else']);
        $other->addMember($user, OrganizationRole::Owner);

        return [$user, OrganizationInvitation::factory()->for($other)->create(['email' => 'theirs@example.org'])];
    });

    expect(app(ChangeMemberRole::class)->blockers($this->organization, $stranger, OrganizationRole::Admin, $this->owner))
        ->toContain(__('members.errors.not_a_member'))
        ->and(app(RemoveMember::class)->blockers($this->organization, $stranger, $this->owner))
        ->toContain(__('members.errors.not_a_member'));

    // The row action itself. Assert on the ROW, not on an exception class: this
    // table is array-backed (->records(fn () => $this->rows())), so a foreign
    // `invitation:` key resolves to no record at all and Members::invitation()'s
    // tenant-scoped firstOrFail is never reached - a different mechanism with
    // the same required outcome.
    try {
        livewire(Members::class)->callTableAction('revoke', 'invitation:'.$theirInvitation->getKey());
    } catch (Throwable) {
        // Whatever Filament answers for a key that is not in the table.
    }

    expect($theirInvitation->fresh()?->revoked_at)->toBeNull()
        ->and($stranger->fresh()?->roleIn($this->organization))->toBeNull();
});

it('refuses the page to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    actingAs($outsider);
    bootOrganizerPanel($this->organization);

    expect(Members::canAccess())->toBeFalse();
});

it('refuses a member ability over a soft-deleted organization instead of throwing', function () {
    // OrganizationMember::organization() can be null twice over: Organization
    // soft-deletes (app/Models/Organization.php:27) while the pivot row
    // survives, and User::roleIn() type-hints a non-nullable Organization
    // (app/Models/User.php:60). Unguarded, a gate that must answer "no" is a
    // TypeError 500 - the same guard SubmissionPolicy has carried since Plan 3.
    $row = OrganizationMember::query()
        ->where('organization_id', $this->organization->getKey())
        ->where('user_id', $this->member->getKey())
        ->firstOrFail();

    $this->organization->delete();

    expect($this->owner->can('view', $row->fresh()))->toBeFalse()
        ->and($this->owner->can('update', $row->fresh()))->toBeFalse()
        ->and($this->owner->can('delete', $row->fresh()))->toBeFalse();
});

it('withdraws the invitations a removed or demoted member had minted', function () {
    // An invitation is the actor's authority, exercised later. A removed owner
    // must not still be able to install an owner through a link they made while
    // they could, and a demoted one must not either.
    $second = User::factory()->create(['email' => 'second.owner@example.org']);
    $this->organization->addMember($second, OrganizationRole::Owner);

    $byOwner = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'owner-invite@example.org',
        'role' => OrganizationRole::Owner,
        'invited_by' => $this->owner->id,
    ]);
    $byAdminAtOwner = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'admin-invite@example.org',
        'role' => OrganizationRole::Member,
        'invited_by' => $this->admin->id,
    ]);

    // Demotion from Owner to Admin: the Owner-level invitations they could no
    // longer mint go; a Member-level one they could still mint stays.
    $byOwnerAtMember = OrganizationInvitation::factory()->for($this->organization)->create([
        'email' => 'still-fine@example.org',
        'role' => OrganizationRole::Member,
        'invited_by' => $this->owner->id,
    ]);

    actingAs($second);
    app(ChangeMemberRole::class)->handle($this->organization, $this->owner, OrganizationRole::Admin, $second);

    expect($byOwner->fresh()?->revoked_at)->not->toBeNull()
        ->and($byOwnerAtMember->fresh()?->revoked_at)->toBeNull()
        ->and($byAdminAtOwner->fresh()?->revoked_at)->toBeNull();

    // Removal takes every live invitation the removed member minted, whatever
    // its role: they manage nothing now.
    app(RemoveMember::class)->handle($this->organization, $this->admin, $second);

    expect($byAdminAtOwner->fresh()?->revoked_at)->not->toBeNull();
});

it('withdraws the reviewer invitations a removed member had minted', function () {
    // The same rule, on the other invitation table. ReviewerInvitationPolicy
    // ::create() admits a plain member, so a member can mint reviewer links to
    // addresses they control; being removed must take them, or a fortnight
    // later they read every submitted abstract and file of the conference.
    $conference = Conference::factory()->for($this->organization)->published()->create();

    $live = ReviewerInvitation::factory()->for($conference)
        ->create(['email' => 'theirs@example.org', 'invited_by' => $this->member->id]);
    $byAnother = ReviewerInvitation::factory()->for($conference)
        ->create(['email' => 'not-theirs@example.org', 'invited_by' => $this->admin->id]);
    $alreadyAccepted = ReviewerInvitation::factory()->for($conference)
        ->create(['email' => 'done@example.org', 'invited_by' => $this->member->id, 'accepted_at' => now()]);

    $elsewhere = withoutTenant(fn (): ReviewerInvitation => ReviewerInvitation::factory()
        ->create(['invited_by' => $this->member->id]));

    app(RemoveMember::class)->handle($this->organization, $this->member, $this->owner);

    expect($live->fresh()?->revoked_at)->not->toBeNull()
        ->and($byAnother->fresh()?->revoked_at)->toBeNull()
        // An accepted invitation is history, not authority: revoking it would
        // rewrite the record of how a sitting reviewer got in.
        ->and($alreadyAccepted->fresh()?->revoked_at)->toBeNull()
        // Another organization's conference is not this action's business.
        ->and($elsewhere->fresh()?->revoked_at)->toBeNull();
});
