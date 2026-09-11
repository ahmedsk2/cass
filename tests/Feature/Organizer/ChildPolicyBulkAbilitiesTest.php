<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\OrganizationRole;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Track;
use App\Models\User;
use App\Policies\CustomFieldPolicy;
use App\Policies\ReviewFormPolicy;
use App\Policies\ReviewQuestionPolicy;
use App\Policies\TrackPolicy;

use function Filament\get_authorization_response;
use function Pest\Laravel\actingAs;

/**
 * Filament resolves a bulk ability through its own helper, and that helper
 * treats a policy without the method as *allowed* (see
 * vendor/filament/filament/src/helpers.php): a child policy that defines only
 * delete() therefore grants deleteAny, restoreAny and forceDeleteAny to
 * everyone the panel lets in. These tests go through the same helper rather
 * than through Gate, so they see what the panel sees.
 *
 * @param  class-string  $model
 */
function panelAllows(string $ability, string $model): bool
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
