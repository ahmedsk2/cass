<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Filament\Reviewer\Resources\Submissions\Pages\ListSubmissions;
use App\Filament\Reviewer\Resources\Submissions\Pages\ReviewSubmission;
use App\Filament\Reviewer\Resources\Submissions\SubmissionResource;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
        'blind_review' => false,
    ]);
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
    $this->form->questions()->orderBy('sort')->skip(2)->take(99)->get()->each->delete();
    [$this->first, $this->second] = $this->form->questions()->orderBy('sort')->get()->all();

    $this->submission = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);
    $this->reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->id]);

    actingAs($this->reviewer);
    bootReviewerPanel();
});

function reviewPage(Submission $submission): object
{
    return livewire(ReviewSubmission::class, ['record' => $submission->getRouteKey()]);
}

it('renders every question of the active form', function () {
    reviewPage($this->submission)
        ->assertOk()
        ->assertSee($this->first->prompt)
        ->assertSee($this->second->prompt)
        ->assertFormFieldExists('answers.'.$this->first->ulid)
        ->assertActionVisible('saveDraft')
        ->assertActionVisible('submitReview')
        ->assertActionHidden('reopenReview');
});

it('saves a draft from the page and fills it back in on the next visit', function () {
    reviewPage($this->submission)
        ->fillForm(['answers.'.$this->first->ulid => 3])
        ->callAction('saveDraft')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $review = Review::query()->firstOrFail();

    expect($review->status)->toBe(ReviewStatus::Draft);

    reviewPage($this->submission->fresh())
        ->assertSchemaStateSet(['answers.'.$this->first->ulid => 3]);
});

it('refuses an incomplete submit with a field error on the missing question', function () {
    reviewPage($this->submission)
        ->fillForm(['answers.'.$this->first->ulid => 4])
        ->callAction('submitReview')
        ->assertHasFormErrors(['answers.'.$this->second->ulid]);

    expect(Review::query()->where('status', ReviewStatus::Submitted->value)->count())->toBe(0);
});

it('submits from the page, moves the abstract and turns the form read-only', function () {
    reviewPage($this->submission)
        ->fillForm([
            'answers.'.$this->first->ulid => 4,
            'answers.'.$this->second->ulid => 5,
        ])
        ->callAction('submitReview')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($this->submission->fresh()?->status)->toBe(SubmissionStatus::UnderReview)
        ->and($this->form->fresh()?->isLocked())->toBeTrue();

    reviewPage($this->submission->fresh())
        ->assertActionHidden('saveDraft')
        ->assertActionHidden('submitReview')
        ->assertActionVisible('reopenReview')
        ->assertSee(__('reviewer.review.submitted_notice'));
});

it('reopens from the page and hides the reopen once the deadline passes', function () {
    reviewPage($this->submission)
        ->fillForm(['answers.'.$this->first->ulid => 4, 'answers.'.$this->second->ulid => 5])
        ->callAction('submitReview');

    reviewPage($this->submission->fresh())
        ->callAction('reopenReview')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Review::query()->firstOrFail()->status)->toBe(ReviewStatus::Draft);

    reviewPage($this->submission->fresh())
        ->fillForm(['answers.'.$this->first->ulid => 4, 'answers.'.$this->second->ulid => 5])
        ->callAction('submitReview');

    Carbon::setTestNow($this->conference->review_deadline->copy()->addMinute());

    reviewPage($this->submission->fresh())
        ->assertActionHidden('reopenReview')
        ->assertSee(__('reviewer.review.deadline_passed'));

    Carbon::setTestNow();
});

it('shows the queue which abstracts are still waiting', function () {
    $done = Submission::factory()->for($this->conference)->submitted()->create(['title' => 'Already reviewed']);

    livewire(ListSubmissions::class)
        ->assertCanRenderTableColumn('review_state')
        ->assertSee(__('reviewer.queue.state.not_started'));

    reviewPage($done)
        ->fillForm(['answers.'.$this->first->ulid => 4, 'answers.'.$this->second->ulid => 5])
        ->callAction('submitReview');

    livewire(ListSubmissions::class)->assertSee(__('reviewer.queue.state.submitted'));
});

it('refuses to write a review for an abstract outside the pool', function () {
    $theirs = Submission::factory()->submitted()->create();

    // Through the route, not livewire(): ReviewSubmission::mount() resolves the
    // record through Filament's InteractsWithRecord::resolveRecord(), which
    // throws ModelNotFoundException for a record the scoped query excludes
    // (InteractsWithRecord.php:42-44), and Livewire's harness rethrows anything
    // that is not an HttpException or an AuthorizationException
    // (RequestBroker.php:29) - so livewire(...)->assertNotFound() would ERROR
    // rather than assert.
    get(SubmissionResource::getUrl('review', ['record' => $theirs], panel: 'reviewer'))->assertNotFound();

    expect($this->reviewer->can('view', $theirs))->toBeFalse();
});

it('lets a reviewer see and change only their own review', function () {
    $other = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $other->id]);
    $theirReview = Review::factory()->for($this->submission)->submitted()->create(['reviewer_user_id' => $other->id]);

    expect($this->reviewer->can('update', $theirReview))->toBeFalse()
        ->and($this->reviewer->can('view', $theirReview))->toBeFalse()
        ->and($other->can('update', $theirReview))->toBeTrue();

    // A second reviewer's review is invisible on the page: this is a review
    // form, not a discussion.
    reviewPage($this->submission)->assertDontSee($theirReview->ulid);
});
