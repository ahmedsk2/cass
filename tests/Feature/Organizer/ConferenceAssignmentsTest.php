<?php

declare(strict_types=1);

use App\Actions\Reviews\AssignReviewers;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Exceptions\ReviewNotAcceptable;
use App\Filament\Organizer\Resources\Conferences\ConferenceResource;
use App\Filament\Organizer\Resources\Conferences\Pages\ConferenceAssignments;
use App\Filament\Organizer\Resources\Conferences\Pages\ViewConference;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->owner = User::factory()->create();
    $this->organization->addMember($this->owner, OrganizationRole::Owner);
    actingAs($this->owner);
    bootOrganizerPanel($this->organization);

    $this->conference = Conference::factory()->for($this->organization)->create([
        'status' => ConferenceStatus::Closed,
        'review_mode' => ReviewMode::Assigned,
        'reviewers_per_submission' => 2,
        'review_deadline' => now()->addMonth(),
        'submission_opens_at' => now()->subMonths(2),
        'submission_deadline' => now()->subWeek(),
    ]);

    $this->submission = Submission::factory()->for($this->conference)->submitted()
        ->create(['title' => 'Early mobilisation after cardiac surgery']);
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();

    $this->omar = User::factory()->create(['name' => 'Dr Omar Khan']);
    $this->sara = User::factory()->create(['name' => 'Dr Sara Nasser']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->omar->id]);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->sara->id]);
});

function assignmentsPage(Conference $conference): object
{
    return livewire(ConferenceAssignments::class, ['record' => $conference->getRouteKey()]);
}

it('lists the submitted abstracts with their reviewers and the coverage summary', function () {
    ReviewAssignment::factory()->for($this->submission)->create(['reviewer_user_id' => $this->omar->id]);
    Submission::factory()->for($this->conference)->create(['title' => 'A draft nobody sent']);

    assignmentsPage($this->conference)
        ->assertOk()
        ->assertSee('AAM26-017')
        ->assertSee('Dr Omar Khan')
        // A draft is not a thing to assign: only `submitted` and
        // `under_review` abstracts appear.
        ->assertDontSee('A draft nobody sent')
        ->assertSee(__('reviewer.assign.coverage_under', ['count' => 1]));
});

it('assigns a set of reviewers and logs the change', function () {
    assignmentsPage($this->conference)
        ->callTableAction('assign', $this->submission, data: [
            'reviewers' => [$this->omar->id, $this->sara->id],
        ])
        ->assertHasNoTableActionErrors();

    expect($this->submission->reviewAssignments()->pluck('reviewer_user_id')->sort()->values()->all())
        ->toBe([$this->omar->id, $this->sara->id])
        ->and($this->submission->reviewAssignments()->first()?->assigned_by)->toBe($this->owner->id)
        ->and(Activity::query()->where('description', 'review.assignments_changed')->count())->toBe(1);
});

it('replaces the set rather than appending to it', function () {
    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id], $this->owner);
    $result = app(AssignReviewers::class)->handle($this->submission, [$this->sara->id], $this->owner);

    expect($result)->toBe(['added' => 1, 'removed' => 1])
        ->and($this->submission->reviewAssignments()->pluck('reviewer_user_id')->all())->toBe([$this->sara->id]);
});

it('is idempotent, so saving the same set twice writes nothing', function () {
    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id], $this->owner);
    $assignedAt = $this->submission->reviewAssignments()->first()?->assigned_at;

    $result = app(AssignReviewers::class)->handle($this->submission, [$this->omar->id], $this->owner);

    expect($result)->toBe(['added' => 0, 'removed' => 0])
        ->and($this->submission->reviewAssignments()->first()?->assigned_at?->toDateTimeString())
        ->toBe($assignedAt?->toDateTimeString());
});

it('refuses a reviewer who is not an active reviewer of this conference', function () {
    $stranger = User::factory()->create();
    $removed = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->removed()->create(['user_id' => $removed->id]);

    expect(app(AssignReviewers::class)->blockers($this->submission, [$stranger->id]))->not->toBe([])
        ->and(app(AssignReviewers::class)->blockers($this->submission, [$removed->id]))->not->toBe([])
        ->and(fn () => app(AssignReviewers::class)->handle($this->submission, [$stranger->id], $this->owner))
        ->toThrow(ReviewNotAcceptable::class);

    expect(ReviewAssignment::query()->count())->toBe(0);
});

it('offers only this conference active reviewers in the picker', function () {
    $elsewhere = User::factory()->create(['name' => 'Somebody Elsewhere']);
    ConferenceReviewer::factory()->create(['user_id' => $elsewhere->id]);

    assignmentsPage($this->conference)
        ->mountTableAction('assign', $this->submission)
        // assertSee() here would read the component HTML from BEFORE the action
        // mounted - Livewire's SubsequentRender forwards the previous html, and
        // Filament's own helpers read effects.partials, which is where the modal
        // is (vendor/filament/actions/src/Testing/TestsActions.php:512, :533).
        // The searchable Select embeds its options in that partial, so only
        // these assertions can see them; the repo records the same trap in
        // tests/Feature/Organizer/ConferenceEmailTemplatesTest.php:135-140.
        ->assertMountedActionModalSee('Dr Omar Khan')
        ->assertMountedActionModalSee('Dr Sara Nasser')
        ->assertMountedActionModalDontSee('Somebody Elsewhere');
});

it('unassigns, keeps a draft review and keeps a submitted one', function () {
    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id, $this->sara->id], $this->owner);

    $draft = Review::factory()->for($this->submission)->create(['reviewer_user_id' => $this->omar->id]);
    $submitted = Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $this->sara->id]);

    assignmentsPage($this->conference)
        ->callTableAction('assign', $this->submission, data: ['reviewers' => [$this->sara->id]])
        ->assertHasNoTableActionErrors();

    expect($this->submission->reviewAssignments()->pluck('reviewer_user_id')->all())->toBe([$this->sara->id])
        // Neither review is touched. A draft simply leaves the queue, because
        // ReviewerScope reads assignments and not reviews; re-assigning the
        // same person brings their work back.
        ->and(Review::query()->whereKey($draft->getKey())->exists())->toBeTrue()
        ->and(Review::query()->whereKey($submitted->getKey())->exists())->toBeTrue();
});

it('counts coverage against reviewers_per_submission', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create();

    app(AssignReviewers::class)->handle($this->submission, [$this->omar->id, $this->sara->id], $this->owner);
    app(AssignReviewers::class)->handle($second, [$this->omar->id], $this->owner);

    expect($this->conference->fresh()?->assignmentCoverage())->toBe([
        'target' => 2,
        'submissions' => 2,
        'covered' => 1,
        'under' => 1,
        'unassigned' => 0,
    ]);
});

it('filters to the abstracts that still need reviewers', function () {
    $second = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Fully covered']);
    $second->forceFill(['reference' => 'AAM26-018'])->save();

    app(AssignReviewers::class)->handle($second, [$this->omar->id, $this->sara->id], $this->owner);

    assignmentsPage($this->conference)
        ->filterTable('under_target')
        ->assertCanSeeTableRecords([$this->submission])
        ->assertCanNotSeeTableRecords([$second]);
});

it('does not exist for an open pool conference', function () {
    $pool = Conference::factory()->for($this->organization)->closed()->create(['review_mode' => ReviewMode::OpenPool]);
    $poolSubmission = Submission::factory()->for($pool)->submitted()->create();

    // This one CAN stay on livewire(): the record resolves (it is this tenant's
    // conference) and the 404 comes from mount()'s
    // `abort_unless(… === ReviewMode::Assigned, 404)`, a NotFoundHttpException,
    // which Livewire's RequestBroker excepts and hands to the real handler
    // (RequestBroker.php:29). The cross-tenant case below cannot, because there
    // resolveRecord() throws ModelNotFoundException instead and the harness
    // rethrows it.
    livewire(ConferenceAssignments::class, ['record' => $pool->getRouteKey()])->assertNotFound();
    get(ConferenceResource::getUrl('assignments', ['record' => $pool]))->assertNotFound();

    expect(app(AssignReviewers::class)->blockers($poolSubmission, []))->not->toBe([]);

    // ... and the link is not offered either.
    livewire(ViewConference::class, [
        'record' => $pool->getRouteKey(),
    ])->assertActionHidden('assignments');
});

it('does not open the assignments page of another organization conference', function () {
    $theirs = withoutTenant(fn (): Conference => Conference::factory()->closed()->create([
        'review_mode' => ReviewMode::Assigned,
    ]));

    // Route only. The tenant-scoped query excludes this record, so
    // resolveRecord() throws ModelNotFoundException
    // (InteractsWithRecord.php:42-44) and Livewire's harness rethrows anything
    // but an HttpException or an AuthorizationException
    // (RequestBroker.php:29) - livewire(...)->assertNotFound() would ERROR here.
    get(ConferenceResource::getUrl('assignments', ['record' => $theirs]))->assertNotFound();
});

it('refuses assignment to somebody with no role in this organization', function () {
    $outsider = User::factory()->create();
    actingAs($outsider);

    expect($outsider->can('create', ReviewAssignment::class))->toBeFalse();
});
