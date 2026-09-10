<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an organization with a ulid, slug and pending status', function () {
    $org = Organization::factory()->create(['name' => 'Gulf Pediatric Society']);

    expect($org->ulid)->toHaveLength(26)
        ->and($org->slug)->toBe('gulf-pediatric-society')
        ->and($org->status)->toBe(OrganizationStatus::Pending)
        ->and($org->isApproved())->toBeFalse();
});

it('links members with a role and exposes owners', function () {
    $org = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $org->addMember($owner, OrganizationRole::Owner);
    $org->addMember($member, OrganizationRole::Member);

    expect($org->members)->toHaveCount(2)
        ->and($org->owners()->pluck('users.id')->all())->toBe([$owner->id])
        ->and($owner->organizations()->first()->is($org))->toBeTrue()
        ->and($owner->roleIn($org))->toBe(OrganizationRole::Owner)
        ->and($member->roleIn($org))->toBe(OrganizationRole::Member);
});

it('answers tenant access questions for filament', function () {
    $org = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = User::factory()->create();
    $org->addMember($user, OrganizationRole::Admin);

    expect($user->canAccessTenant($org))->toBeTrue()
        ->and($user->canAccessTenant($other))->toBeFalse();
});

it('does not accept status or slug through mass assignment', function () {
    $org = Organization::factory()->make();
    $org->fill(['name' => 'Renamed']);

    expect(fn () => $org->fill(['status' => 'approved']))->toThrow(MassAssignmentException::class)
        ->and(fn () => $org->fill(['slug' => 'taken']))->toThrow(MassAssignmentException::class);
});

it('reserves slugs of soft-deleted organizations and returns null role for non-members', function () {
    $first = Organization::factory()->create(['name' => 'Gulf Society']);
    $first->delete();
    $second = Organization::factory()->create(['name' => 'Gulf Society']);
    $outsider = User::factory()->create();

    expect($second->slug)->toBe('gulf-society-2')
        ->and($outsider->roleIn($second))->toBeNull();
});
