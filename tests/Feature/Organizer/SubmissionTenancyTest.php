<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The organizer panel's tenancy on Submission is a mechanism for every query in the panel, not
 * a convention in one resource. It reaches the organization through the abstract's conference,
 * so there is no copied value to keep right.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $user = User::factory()->create();
    $this->organization->addMember($user, OrganizationRole::Owner);
    actingAs($user);

    $this->theirConference = Conference::factory()->for(Organization::factory()->approved())->published()->create();
    $this->mine = Submission::factory()->for(Conference::factory()->for($this->organization)->published())->submitted()->create();
    $this->theirs = Submission::factory()->for($this->theirConference)->submitted()->create();

    bootOrganizerPanel($this->organization);
});

it('scopes every submission query in the organizer panel to the tenant, through the conference', function () {
    expect(SubmissionResource::isScopedToTenant())->toBeTrue()
        ->and(SubmissionResource::getTenantOwnershipRelationshipName())->toBe('organization')
        ->and(Submission::query()->pluck('id')->all())->toBe([$this->mine->id])
        ->and(Submission::query()->find($this->theirs->id))->toBeNull()
        // A page that reads abstracts through a conference is scoped as well.
        ->and($this->theirConference->submissions()->count())->toBe(0);
});

it('creates a submission while the panel is booted for another tenant, and leaves it that tenant', function () {
    // Filament's tenancy observers run on every creation in the booted panel. Through a
    // has-one-through they neither associate the current tenant nor try to save the relation,
    // which has no save(): this is the case that would fail first if either changed.
    $created = Submission::factory()->for($this->theirConference)->create();

    expect($created->fresh()?->conference_id)->toBe($this->theirConference->id)
        ->and($created->fresh()?->organization?->is($this->theirConference->organization))->toBeTrue();
});
