<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationMembership;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The membership question the tenant-owned policies share. Its checks are
 * protected, because a public method on a policy is an ability the Gate would
 * answer, so the test reaches them through a class that re-exports them.
 */
beforeEach(function () {
    $this->check = new class
    {
        use ChecksOrganizationMembership {
            isMemberOf as public;
            managesOrganization as public;
            isMemberOfCurrentTenant as public;
            managesCurrentTenant as public;
        }
    };

    $this->organization = Organization::factory()->approved()->create();
    $this->member = User::factory()->create();
    $this->organization->addMember($this->member, OrganizationRole::Member);

    // The strongest role somewhere else, which must count for nothing here.
    $this->other = Organization::factory()->approved()->create();
    $this->otherOwner = User::factory()->create();
    $this->other->addMember($this->otherOwner, OrganizationRole::Owner);

    $this->outsider = User::factory()->create();
});

it('admits every role as a member and only an owner or admin as a manager', function (OrganizationRole $role, bool $manages) {
    $user = User::factory()->create();
    $this->organization->addMember($user, $role);

    expect($this->check->isMemberOf($user, $this->organization))->toBeTrue()
        ->and($this->check->managesOrganization($user, $this->organization))->toBe($manages);
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'admin' => [OrganizationRole::Admin, true],
    'member' => [OrganizationRole::Member, false],
]);

it('refuses a user with no role in the organization', function () {
    expect($this->check->isMemberOf($this->outsider, $this->organization))->toBeFalse()
        ->and($this->check->managesOrganization($this->outsider, $this->organization))->toBeFalse()
        ->and($this->check->isMemberOf($this->otherOwner, $this->organization))->toBeFalse()
        ->and($this->check->managesOrganization($this->otherOwner, $this->organization))->toBeFalse()
        // The answer is per organization: the same user still owns their own.
        ->and($this->check->isMemberOf($this->otherOwner, $this->other))->toBeTrue()
        ->and($this->check->managesOrganization($this->otherOwner, $this->other))->toBeTrue();
});

it('gives the platform admin no membership of its own', function () {
    // The platform admin's grant is each policy's own: a before() in most,
    // an inline `is_platform_admin ||` in OrganizationPolicy, and none at all
    // in the track, custom field, review form and review question policies,
    // which refuse an admin who holds no role. So this answers by role alone.
    $admin = User::factory()->platformAdmin()->create();

    Filament::setTenant($this->organization, isQuiet: true);

    expect($this->check->isMemberOf($admin, $this->organization))->toBeFalse()
        ->and($this->check->managesOrganization($admin, $this->organization))->toBeFalse()
        ->and($this->check->isMemberOfCurrentTenant($admin))->toBeFalse()
        ->and($this->check->managesCurrentTenant($admin))->toBeFalse();

    // A platform admin who also holds a role is that role here, nothing more.
    $this->organization->addMember($admin, OrganizationRole::Member);

    expect($this->check->isMemberOf($admin, $this->organization))->toBeTrue()
        ->and($this->check->managesOrganization($admin, $this->organization))->toBeFalse();
});

it('answers the same whatever the organization status', function () {
    // No policy reads the status. A pending or suspended organization's
    // members keep their roles; what such an organization may not do -
    // publish, invite, send reminders - is refused by the Actions.
    $pending = Organization::factory()->create();
    $suspended = Organization::factory()->suspended()->create();

    foreach ([$pending, $suspended] as $organization) {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization->addMember($owner, OrganizationRole::Owner);
        $organization->addMember($member, OrganizationRole::Member);

        Filament::setTenant($organization, isQuiet: true);

        expect($this->check->isMemberOf($member, $organization))->toBeTrue()
            ->and($this->check->managesOrganization($owner, $organization))->toBeTrue()
            ->and($this->check->managesOrganization($member, $organization))->toBeFalse()
            ->and($this->check->isMemberOfCurrentTenant($member))->toBeTrue()
            ->and($this->check->managesCurrentTenant($owner))->toBeTrue()
            ->and($this->check->isMemberOf($this->outsider, $organization))->toBeFalse();
    }
});

it('asks the same of the current tenant', function () {
    // Without a tenant - the reviewer panel has none - nobody is a member.
    expect($this->check->isMemberOfCurrentTenant($this->member))->toBeFalse()
        ->and($this->check->managesCurrentTenant($this->otherOwner))->toBeFalse();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    $this->organization->addMember($admin, OrganizationRole::Admin);

    Filament::setTenant($this->organization, isQuiet: true);

    expect($this->check->isMemberOfCurrentTenant($owner))->toBeTrue()
        ->and($this->check->isMemberOfCurrentTenant($admin))->toBeTrue()
        ->and($this->check->isMemberOfCurrentTenant($this->member))->toBeTrue()
        ->and($this->check->managesCurrentTenant($owner))->toBeTrue()
        ->and($this->check->managesCurrentTenant($admin))->toBeTrue()
        ->and($this->check->managesCurrentTenant($this->member))->toBeFalse()
        ->and($this->check->isMemberOfCurrentTenant($this->outsider))->toBeFalse()
        ->and($this->check->isMemberOfCurrentTenant($this->otherOwner))->toBeFalse()
        ->and($this->check->managesCurrentTenant($this->otherOwner))->toBeFalse();

    Filament::setTenant($this->other, isQuiet: true);

    expect($this->check->isMemberOfCurrentTenant($owner))->toBeFalse()
        ->and($this->check->managesCurrentTenant($owner))->toBeFalse()
        ->and($this->check->managesCurrentTenant($this->otherOwner))->toBeTrue();
});

it('answers for the organization a track reaches through its conference', function () {
    $conference = Conference::factory()->for($this->organization)->create();
    $track = Track::factory()->for($conference)->create()->fresh();

    // The organization reached from a track through its conference, one hop:
    // the check answers for it as for the organization itself.
    $organization = $track->conference->organization;

    expect($organization->is($this->organization))->toBeTrue()
        ->and($this->check->isMemberOf($this->member, $organization))->toBeTrue()
        ->and($this->check->managesOrganization($this->member, $organization))->toBeFalse()
        ->and($this->check->isMemberOf($this->outsider, $organization))->toBeFalse()
        ->and($this->check->isMemberOf($this->otherOwner, $organization))->toBeFalse();
});

it('answers for the organization a review reaches through its submission', function () {
    $conference = Conference::factory()->for($this->organization)->published()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $review = Review::factory()->for($submission)->create()->fresh();

    // The organization reached from a review through its submission and its
    // conference, two hops: the check answers for it as for the organization
    // itself.
    $organization = $review->submission->conference->organization;

    expect($organization->is($this->organization))->toBeTrue()
        ->and($this->check->isMemberOf($this->member, $organization))->toBeTrue()
        ->and($this->check->isMemberOf($this->outsider, $organization))->toBeFalse()
        ->and($this->check->isMemberOf($this->otherOwner, $organization))->toBeFalse()
        // The review's author holds no role: ReviewPolicy lets them read it by
        // its own-row rule, never through membership.
        ->and($this->check->isMemberOf($review->reviewer, $organization))->toBeFalse();
});

it('leaves a broken walk to the policy that walks it', function () {
    // A soft-deleted organization, or a conference the tenancy scope hides,
    // reaches a policy as null, and what that means is the policy's own
    // decision: most guard the hop and answer no, while ConferencePolicy,
    // ReviewFormPolicy and ReviewQuestionPolicy walk it unguarded. So the
    // check takes a non-nullable Organization, as User::roleIn() does, and
    // never answers for a missing one.
    expect(fn () => $this->check->isMemberOf($this->member, null))->toThrow(TypeError::class)
        ->and(fn () => $this->check->managesOrganization($this->member, null))->toThrow(TypeError::class);
});
