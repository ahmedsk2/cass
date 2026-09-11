<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Policies\EmailTemplatePolicy;
use App\Policies\SubmissionFilePolicy;
use App\Policies\SubmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Conference, Organization and Submission all soft delete, and the foreign keys
 * only restrict a *hard* delete - so every `->conference->organization` walk in
 * a policy can resolve to null while the child row survives. `User::roleIn()`
 * takes a non-nullable Organization, so an unguarded walk is a TypeError, which
 * is a 500 on a gate whose whole job is to answer "no".
 *
 * One case per policy per link in the chain. None of them is reachable from a
 * panel today (the tenancy scope hides the row first); all of them are
 * reachable the moment a platform-admin screen lists submissions across
 * organizations, which Plan 6 adds.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);

    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
});

it('denies a submission whose conference is trashed', function () {
    $this->conference->delete();

    expect(app(SubmissionPolicy::class)->view($this->user, $this->submission->fresh()))->toBeFalse();
});

it('denies a submission whose organization is trashed', function () {
    $this->organization->delete();

    expect(app(SubmissionPolicy::class)->view($this->user, $this->submission->fresh()))->toBeFalse();
});

it('denies a file whose conference is trashed', function () {
    $file = SubmissionFile::factory()->for($this->submission)->create();

    $this->conference->delete();

    expect(app(SubmissionFilePolicy::class)->view($this->user, $file->fresh()))->toBeFalse();
});

it('denies a file whose abstract is trashed', function () {
    $file = SubmissionFile::factory()->for($this->submission)->create();

    $this->submission->delete();

    expect(app(SubmissionFilePolicy::class)->view($this->user, $file->fresh()))->toBeFalse();
});

it('denies a template whose conference is trashed', function () {
    $template = EmailTemplate::factory()->for($this->conference)->create();

    $this->conference->delete();

    expect(app(EmailTemplatePolicy::class)->view($this->user, $template->fresh()))->toBeFalse();
});

it('denies a template whose organization is trashed', function () {
    $template = EmailTemplate::factory()->for($this->conference)->create();

    $this->organization->delete();

    expect(app(EmailTemplatePolicy::class)->view($this->user, $template->fresh()))->toBeFalse();
});
