<?php

declare(strict_types=1);

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\ReviewerInvitation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives pending, expired, accepted and revoked from the timestamps', function () {
    $invitation = OrganizationInvitation::factory()->create();

    expect($invitation->invitationStatus())->toBe(InvitationStatus::Pending);

    Carbon::setTestNow($invitation->expires_at->copy()->addSecond());
    expect($invitation->invitationStatus())->toBe(InvitationStatus::Expired);
    Carbon::setTestNow();

    $accepted = OrganizationInvitation::factory()->create();
    $accepted->forceFill(['accepted_at' => now()])->save();
    expect($accepted->invitationStatus())->toBe(InvitationStatus::Accepted);

    // Revoked wins over accepted and over expired: an organizer who withdraws
    // an invitation must see that answer whatever the clock says.
    $revoked = OrganizationInvitation::factory()->create();
    $revoked->forceFill(['revoked_at' => now()])->save();
    expect($revoked->invitationStatus())->toBe(InvitationStatus::Revoked)
        ->and($revoked->invitationStatus()->isOpen())->toBeFalse()
        ->and(InvitationStatus::Pending->isOpen())->toBeTrue();
});

it('expires fourteen days out by default and says so in both tables', function () {
    Carbon::setTestNow('2026-09-11 08:00:00');

    expect(OrganizationInvitation::factory()->create()->expires_at->toDateString())->toBe('2026-09-25')
        ->and(ReviewerInvitation::factory()->create()->expires_at->toDateString())->toBe('2026-09-25')
        ->and((int) config('cass.invitations.expiry_days'))->toBe(14);

    Carbon::setTestNow();
});

it('refuses mass assignment on both invitation tables', function () {
    // Every column is written by an action with forceFill(). A fillable
    // token_hash or role is a privilege escalation with a form field attached.
    expect(fn () => new OrganizationInvitation(['role' => OrganizationRole::Owner->value]))
        ->toThrow(MassAssignmentException::class)
        ->and(fn () => new ReviewerInvitation(['email' => 'x@example.org']))
        ->toThrow(MassAssignmentException::class);
});

it('grants organization membership and keeps an existing role', function () {
    $organization = Organization::factory()->approved()->create();
    $user = User::factory()->create();

    OrganizationInvitation::factory()->for($organization)->create(['role' => OrganizationRole::Admin])
        ->grantTo($user);

    expect($user->roleIn($organization))->toBe(OrganizationRole::Admin);

    // A second invitation at a lower role must not demote a sitting admin:
    // addMember() is syncWithoutDetaching, sync() updates the pivot of an id it
    // already holds, and a role change is ChangeMemberRole's job with its own
    // rules - so grantTo() returns early for anyone who is already a member.
    OrganizationInvitation::factory()->for($organization)->create(['role' => OrganizationRole::Member])
        ->grantTo($user);

    expect($user->fresh()?->roleIn($organization))->toBe(OrganizationRole::Admin);

    // And the escalation in the other direction, which is the one that matters:
    // a stale Owner invitation must not promote a sitting admin either, or a
    // link minted weeks ago becomes a route around ChangeMemberRole's
    // owner-grants-owner rule.
    OrganizationInvitation::factory()->for($organization)->create(['role' => OrganizationRole::Owner])
        ->grantTo($user);

    expect($user->fresh()?->roleIn($organization))->toBe(OrganizationRole::Admin);
});

it('grants a conference reviewership and reactivates a removed one', function () {
    $conference = Conference::factory()->create();
    $user = User::factory()->create();

    ReviewerInvitation::factory()->for($conference)->create(['affiliation' => 'KFSH'])->grantTo($user);

    $reviewer = ConferenceReviewer::query()->firstOrFail();

    expect($reviewer->conference_id)->toBe($conference->id)
        ->and($reviewer->user_id)->toBe($user->id)
        ->and($reviewer->status)->toBe(ReviewerStatus::Active)
        ->and($reviewer->affiliation)->toBe('KFSH')
        ->and($reviewer->accepted_at)->not->toBeNull();

    $reviewer->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();

    ReviewerInvitation::factory()->for($conference)->create()->grantTo($user);

    expect(ConferenceReviewer::query()->count())->toBe(1)
        ->and($reviewer->fresh()?->status)->toBe(ReviewerStatus::Active)
        ->and($reviewer->fresh()?->removed_at)->toBeNull();
});

it('lands an organization invitee in that tenant and a reviewer in the reviewer panel', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);

    expect(OrganizationInvitation::factory()->for($organization)->create()->landingUrl())
        ->toContain('/org/'.$organization->slug)
        ->and(ReviewerInvitation::factory()->create()->landingUrl())
        ->toEndWith('/review');
});

it('names who is inviting, in both tables', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $conference = Conference::factory()->for($organization)->create(['name' => 'Alpha Annual Meeting']);

    $member = OrganizationInvitation::factory()->for($organization)->create([
        'email' => 'NEW@Example.ORG',
        'role' => OrganizationRole::Admin,
    ]);
    $reviewer = ReviewerInvitation::factory()->for($conference)->create(['name' => 'Dr Omar Khan']);

    expect($member->invitingOrganizationName())->toBe('Alpha Society')
        ->and($member->invitedName())->toBeNull()
        ->and($member->invitationHeadline())->toContain('Alpha Society')
        ->and($reviewer->invitingOrganizationName())->toBe('Alpha Society')
        ->and($reviewer->invitedName())->toBe('Dr Omar Khan')
        ->and($reviewer->invitationHeadline())->toContain('Alpha Annual Meeting');
});
