<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\ReviewerInvitation;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\Track;
use App\Models\User;
use App\Policies\ConferencePolicy;
use App\Policies\CustomFieldPolicy;
use App\Policies\ReviewFormPolicy;
use App\Policies\ReviewQuestionPolicy;
use App\Policies\SubmissionDecisionPolicy;
use App\Policies\SubmissionPolicy;
use App\Policies\TrackPolicy;

use function Filament\get_authorization_response;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

/**
 * Filament resolves a bulk ability through its own helper, and that helper
 * treats a policy without the method as *allowed* (see
 * vendor/filament/filament/src/helpers.php): a child policy that defines only
 * delete() therefore grants deleteAny, restoreAny and forceDeleteAny to
 * everyone the panel lets in. These tests go through the same helper rather
 * than through Gate, so they see what the panel sees.
 *
 * A class-string asks the class-level question (viewAny, create, deleteAny);
 * a Model instance asks the row-level one (view, update, delete on *this*
 * row), which is the only way to exercise a policy's second argument - and
 * therefore the only way to prove the soft-deleted-organization guard below.
 *
 * @param  class-string|Model  $model
 */
function panelAllows(string $ability, Model|string $model): bool
{
    return get_authorization_response($ability, $model)->allowed();
}

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->member = User::factory()->create();
    $this->organization->addMember($this->member, OrganizationRole::Member);

    // No role in this organization at all, which is what every other tenant's
    // members look like from here.
    $this->outsider = User::factory()->create();
    $this->admin = User::factory()->platformAdmin()->create();

    actingAs($this->member);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->create();
});

it('mirrors the single track delete in the bulk abilities', function () {
    $track = Track::factory()->for($this->conference)->create();
    $policy = app(TrackPolicy::class);

    // A member of the organization may delete one track, so the bulk ability
    // says the same; someone with no role here may do neither.
    expect($policy->delete($this->member, $track))->toBeTrue()
        ->and(panelAllows('deleteAny', Track::class))->toBeTrue()
        ->and($policy->delete($this->outsider, $track))->toBeFalse();

    actingAs($this->outsider);
    expect(panelAllows('deleteAny', Track::class))->toBeFalse();

    // Tracks are not soft-deletable, so restoring and force-deleting are never
    // on offer - to a platform admin no more than to anybody else.
    foreach ([$this->member, $this->outsider, $this->admin] as $user) {
        actingAs($user);

        expect(panelAllows('restoreAny', Track::class))->toBeFalse()
            ->and(panelAllows('forceDeleteAny', Track::class))->toBeFalse();
    }
});

it('mirrors the single custom field delete in the bulk abilities', function () {
    $field = CustomField::factory()->for($this->conference)->create();
    $policy = app(CustomFieldPolicy::class);

    expect($policy->delete($this->member, $field))->toBeTrue()
        ->and(panelAllows('deleteAny', CustomField::class))->toBeTrue()
        ->and($policy->delete($this->outsider, $field))->toBeFalse();

    actingAs($this->outsider);
    expect(panelAllows('deleteAny', CustomField::class))->toBeFalse();

    foreach ([$this->member, $this->outsider, $this->admin] as $user) {
        actingAs($user);

        expect(panelAllows('restoreAny', CustomField::class))->toBeFalse()
            ->and(panelAllows('forceDeleteAny', CustomField::class))->toBeFalse();
    }
});

it('refuses every review form bulk ability because nobody may delete one', function () {
    $form = app(CreateDefaultReviewForm::class)->handle($this->conference);

    // A review form is the record of what reviewers were asked, so delete() is
    // false for everybody - and the bulk ability has to say the same.
    expect(app(ReviewFormPolicy::class)->delete($this->member, $form))->toBeFalse();

    foreach ([$this->member, $this->outsider, $this->admin] as $user) {
        actingAs($user);

        expect(panelAllows('deleteAny', ReviewForm::class))->toBeFalse()
            ->and(panelAllows('restoreAny', ReviewForm::class))->toBeFalse()
            ->and(panelAllows('forceDeleteAny', ReviewForm::class))->toBeFalse();
    }
});

it('refuses every review question bulk ability because the lock needs a record', function () {
    $form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    $question = $form->questions()->firstOrFail();

    // Deleting one question is allowed only while the form is unlocked, and a
    // bulk ability is handed no record to check the lock against, so it is
    // refused outright rather than guessing.
    expect(app(ReviewQuestionPolicy::class)->delete($this->member, $question))->toBeTrue();

    foreach ([$this->member, $this->outsider, $this->admin] as $user) {
        actingAs($user);

        expect(panelAllows('deleteAny', ReviewQuestion::class))->toBeFalse()
            ->and(panelAllows('restoreAny', ReviewQuestion::class))->toBeFalse()
            ->and(panelAllows('forceDeleteAny', ReviewQuestion::class))->toBeFalse();
    }
});

it('refuses every bulk ability on the five plan 4 models', function () {
    // Fact 25: Filament treats a missing policy method as ALLOW, and none of
    // these five models has any bulk UI in any panel, so each policy spells
    // deleteAny, restoreAny and forceDeleteAny out and answers false for every
    // organization member and every outsider. deleteAny is the one Filament
    // actually calls, so it is asserted first rather than left implied.
    $models = [
        OrganizationInvitation::class,
        ReviewerInvitation::class,
        ConferenceReviewer::class,
        ReviewAssignment::class,
        Review::class,
    ];

    // The platform admin is deliberately NOT in this loop. Unlike the four child
    // policies this file already covers - Track, CustomField, ReviewForm and
    // ReviewQuestion, none of which has a before() at all - all five Plan 4
    // policies declare `before(): ?bool` answering true for is_platform_admin,
    // and Laravel resolves before() AHEAD of the ability itself
    // (Gate::resolvePolicyCallback, vendor/laravel/framework/src/Illuminate/
    // Auth/Access/Gate.php:791-800 returns the non-null before() result without
    // ever calling the method). An explicit `deleteAny(): false` therefore does
    // not beat it, exactly as App\Policies\SubmissionPolicy's own docblock
    // already records and accepts. Asserting otherwise would be asserting
    // against the design.
    foreach ([$this->member, $this->outsider] as $user) {
        actingAs($user);

        foreach ($models as $model) {
            expect(panelAllows('deleteAny', $model))->toBeFalse("deleteAny on {$model}")
                ->and(panelAllows('restoreAny', $model))->toBeFalse("restoreAny on {$model}")
                ->and(panelAllows('forceDeleteAny', $model))->toBeFalse("forceDeleteAny on {$model}");
        }
    }

    // What a platform admin actually gets, pinned here so nobody "fixes" the
    // loop by adding them back, and so Plan 6 adding a screen behind these
    // policies is a decision rather than a discovery: the read-only admin
    // resources it brings have to refuse deletion in before() or in the
    // resource, because the policy method is never reached.
    actingAs($this->admin);

    foreach ($models as $model) {
        expect(panelAllows('deleteAny', $model))->toBeTrue("deleteAny on {$model}")
            ->and(panelAllows('restoreAny', $model))->toBeTrue("restoreAny on {$model}");
    }
});

it('refuses a plan 4 ability over a soft-deleted organization instead of throwing', function () {
    // Organization soft-deletes (app/Models/Organization.php:27) while its
    // conferences and their rows survive, and User::roleIn() type-hints a
    // non-nullable Organization (app/Models/User.php:60) - so a policy that
    // walks conference->organization unguarded turns a gate that must answer
    // "no" into a TypeError 500. SubmissionPolicy already guards that second
    // hop and says why in its docblock; these five now do the same.
    $conference = Conference::factory()->for($this->organization)->create();
    $submission = Submission::factory()->for($conference)->submitted()->create();
    $reviewerRow = ConferenceReviewer::factory()->for($conference)->create();
    $invitation = ReviewerInvitation::factory()->for($conference)->create();
    $assignment = ReviewAssignment::factory()->for($submission)->create();
    $review = Review::factory()->for($submission)->create();

    $this->organization->delete();

    actingAs($this->member);

    expect(panelAllows('view', $invitation->fresh()))->toBeFalse()
        ->and(panelAllows('view', $reviewerRow->fresh()))->toBeFalse()
        ->and(panelAllows('update', $reviewerRow->fresh()))->toBeFalse()
        ->and(panelAllows('view', $assignment->fresh()))->toBeFalse()
        ->and(panelAllows('delete', $assignment->fresh()))->toBeFalse()
        ->and(panelAllows('view', $review->fresh()))->toBeFalse();
});

it('lets an owner and admin manage invitations and refuses a plain member', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);
    $this->organization->addMember($admin, OrganizationRole::Admin);

    foreach ([$owner, $admin] as $user) {
        actingAs($user);
        expect(panelAllows('create', OrganizationInvitation::class))->toBeTrue()
            ->and(panelAllows('viewAny', OrganizationInvitation::class))->toBeTrue();
    }

    // Spec section 4: "Manage organization members" is owner and admin only,
    // while "Invite reviewers, assign, decide" is every member - so the two
    // invitation policies deliberately answer differently for the same user.
    actingAs($this->member);
    expect(panelAllows('create', OrganizationInvitation::class))->toBeFalse()
        ->and(panelAllows('create', ReviewerInvitation::class))->toBeTrue()
        ->and(panelAllows('create', ReviewAssignment::class))->toBeTrue();

    actingAs($this->outsider);
    expect(panelAllows('create', OrganizationInvitation::class))->toBeFalse()
        ->and(panelAllows('create', ReviewerInvitation::class))->toBeFalse()
        ->and(panelAllows('create', ReviewAssignment::class))->toBeFalse();
});

it('never offers a bulk delete of the decision history', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionDecision::factory()->for($submission)->create();
    $policy = app(SubmissionDecisionPolicy::class);

    // Filament treats a policy WITHOUT the method as allowed
    // (vendor/filament/filament/src/helpers.php), so every ability a table may
    // ask for is spelled out. The history is append-only for everyone the
    // panel lets in: a decision that can be deleted is a decision that can be
    // denied.
    expect(panelAllows('viewAny', SubmissionDecision::class))->toBeTrue()
        ->and(panelAllows('create', SubmissionDecision::class))->toBeFalse()
        ->and(panelAllows('deleteAny', SubmissionDecision::class))->toBeFalse()
        ->and(panelAllows('restoreAny', SubmissionDecision::class))->toBeFalse()
        ->and(panelAllows('forceDeleteAny', SubmissionDecision::class))->toBeFalse()
        ->and($policy->update($this->member, $submission->decisions()->firstOrFail()))->toBeFalse()
        ->and($policy->delete($this->member, $submission->decisions()->firstOrFail()))->toBeFalse();

    actingAs($this->outsider);
    expect(panelAllows('viewAny', SubmissionDecision::class))->toBeFalse();
});

it('lets a member decide but only an owner or admin send the letters', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    $submissionPolicy = app(SubmissionPolicy::class);
    $conferencePolicy = app(ConferencePolicy::class);

    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    // Spec section 4 puts "Invite reviewers, assign, decide" on every
    // organization member, so deciding is a member's job. SENDING is a
    // bulk-mail primitive over every author in the conference, which this plan
    // narrows to owner/admin and records as an owner question.
    expect($submissionPolicy->decide($this->member, $submission))->toBeTrue()
        ->and($conferencePolicy->sendDecisions($this->member, $submission->conference))->toBeFalse()
        ->and($submissionPolicy->decide($owner, $submission))->toBeTrue()
        ->and($conferencePolicy->sendDecisions($owner, $submission->conference))->toBeTrue()
        ->and($submissionPolicy->decide($this->outsider, $submission))->toBeFalse()
        ->and($conferencePolicy->sendDecisions($this->outsider, $submission->conference))->toBeFalse();

    // Through the Gate, not the object: this ability is always asked with a
    // Conference, so the policy Laravel resolves from that first argument is
    // the thing under test. Defined on SubmissionPolicy it would resolve
    // ConferencePolicy, find no method, and answer false for everybody.
    expect(Gate::forUser($owner)->allows('sendDecisions', $submission->conference))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('sendDecisions', $submission->conference))->toBeFalse();
});
