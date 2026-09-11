<?php

declare(strict_types=1);

use App\Actions\Conferences\CreateDefaultReviewForm;
use App\Actions\Reviews\SaveReviewDraft;
use App\Actions\Reviews\SubmitReview;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewQuestionType;
use App\Exceptions\ReviewQuestionInUse;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\ReviewQuestionsRelationManager;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Filament\Forms\Components\Repeater;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    $this->conference = Conference::factory()->for($this->organization)->create();
    $this->form = app(CreateDefaultReviewForm::class)->handle($this->conference);
});

function reviewQuestionsManager(Conference $conference): object
{
    return livewire(ReviewQuestionsRelationManager::class, [
        'ownerRecord' => $conference,
        'pageClass' => EditConference::class,
    ]);
}

it('shows the nine template questions in order', function () {
    reviewQuestionsManager($this->conference)
        ->assertCanSeeTableRecords($this->form->questions()->get())
        ->assertSee('Originality and Innovation: How original and innovative is the research presented in the abstract?');
});

it('adds a question to the active form', function () {
    reviewQuestionsManager($this->conference)
        ->callTableAction('create', data: [
            'prompt' => 'Is the abstract free of identifying information?',
            'type' => ReviewQuestionType::Boolean->value,
            'weight' => '0.50',
            'required' => false,
        ])
        ->assertHasNoTableActionErrors();

    $question = ReviewQuestion::query()->where('prompt', 'Is the abstract free of identifying information?')->firstOrFail();

    expect($question->review_form_id)->toBe($this->form->id)
        ->and($question->type)->toBe(ReviewQuestionType::Boolean)
        ->and($question->weight)->toBe('0.50')
        // Appended, not first: Filament sets no sort on create, so the model
        // hook has to (spec section 3).
        ->and($question->sort)->toBe(10)
        ->and($this->form->questions()->count())->toBe(10);
});

it('stores the likert scale and the scored choices of a select question', function () {
    // Both fields are behind a `visible()` closure that reads $get('type').
    // Comparing that with ->value instead of the enum case would keep them
    // hidden for ever, and a hidden field is not dehydrated - the questions
    // would be saved with NULL scale bounds and NULL choices, which Plan 5
    // could not score and a locked form could never repair.
    $undo = Repeater::fake();

    try {
        reviewQuestionsManager($this->conference)
            ->callTableAction('create', data: [
                'prompt' => 'Overall quality',
                'type' => ReviewQuestionType::Likert->value,
                'weight' => '2.00',
                'scale_min' => 1,
                'scale_max' => 7,
                'required' => true,
            ])
            ->assertHasNoTableActionErrors()
            ->callTableAction('create', data: [
                'prompt' => 'Preferred format',
                'type' => ReviewQuestionType::Select->value,
                'weight' => '1.00',
                'options' => [
                    ['label' => 'Oral', 'score' => 100],
                    ['label' => 'Poster', 'score' => 50],
                    ['label' => 'Either', 'score' => null],
                ],
                'required' => true,
            ])
            ->assertHasNoTableActionErrors();
    } finally {
        $undo();
    }

    $likert = ReviewQuestion::query()->where('prompt', 'Overall quality')->firstOrFail();
    $select = ReviewQuestion::query()->where('prompt', 'Preferred format')->firstOrFail();

    expect($likert->scale_min)->toBe(1)
        ->and($likert->scale_max)->toBe(7)
        ->and($likert->weight)->toBe('2.00')
        ->and(array_column($select->options ?? [], 'label'))->toBe(['Oral', 'Poster', 'Either'])
        ->and((int) ($select->options[0]['score'] ?? 0))->toBe(100)
        // `??` collapses a null value into the sentinel, so the key has to be
        // probed with array_key_exists: the third choice must be stored *with*
        // a score key whose value is null, not dropped from the row.
        ->and(array_key_exists('score', $select->options[2] ?? []) ? $select->options[2]['score'] : 'missing')->toBeNull()
        ->and($select->isScored())->toBeTrue();
});

it('edits, reorders and deletes questions while the form is unlocked', function () {
    $questions = $this->form->questions()->get();
    $first = $questions->first();
    $second = $questions->get(1);

    reviewQuestionsManager($this->conference)
        ->callTableAction('edit', $first, data: ['prompt' => 'Originality of the work'])
        ->assertHasNoTableActionErrors()
        ->call('reorderTable', [$second->getKey(), $first->getKey()])
        ->callTableAction('delete', $questions->last());

    expect($first->fresh()?->prompt)->toBe('Originality of the work')
        ->and($this->form->questions()->first()?->is($second))->toBeTrue()
        ->and($this->form->questions()->count())->toBe(8);
});

it('creates the form on demand for a conference that has none', function () {
    $bare = Conference::factory()->for($this->organization)->create();
    $bare->reviewForms()->delete();

    reviewQuestionsManager($bare)->assertSee('Review form');

    expect($bare->fresh()?->reviewForm?->questions()->count())->toBe(9);
});

it('locks editing, reordering and deleting once reviews exist', function () {
    $this->form->forceFill(['locked_at' => now()])->save();
    $question = $this->form->questions()->first();

    reviewQuestionsManager($this->conference->fresh())
        ->assertSee('Locked')
        ->assertTableActionHidden('edit', $question)
        ->assertTableActionHidden('delete', $question)
        ->assertTableActionVisible('create');

    expect($this->user->can('update', $question))->toBeFalse()
        ->and($this->user->can('delete', $question))->toBeFalse()
        ->and($this->user->can('create', ReviewQuestion::class))->toBeTrue();
});

it('is locked by a real submitted review, not only by a hand-set timestamp', function () {
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);
    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    $answers = $this->form->questions()->get()
        ->mapWithKeys(fn (ReviewQuestion $question): array => [$question->ulid => 4])
        ->all();

    app(SubmitReview::class)->handle($submission->fresh(), $reviewer, $answers);

    $question = $this->form->questions()->first();

    reviewQuestionsManager($this->conference->fresh())
        ->assertSee('Locked')
        ->assertTableActionHidden('edit', $question)
        ->assertTableActionHidden('delete', $question)
        // Spec section 3: appending is still allowed on a locked form.
        ->assertTableActionVisible('create');

    expect($this->form->fresh()?->isLocked())->toBeTrue();
});

it('refuses to delete a question that already carries a draft answer', function () {
    // The window nothing covered: SaveReviewDraft writes review_answers rows and
    // never touches locked_at - only SubmitReview locks - so between the first
    // draft save and the first submit the form is UNLOCKED, the Delete button
    // was live, and review_answers.review_question_id is restrictOnDelete. The
    // click was a foreign-key QueryException 500, not a refusal.
    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $reviewer->id]);
    $this->conference->forceFill(['status' => ConferenceStatus::Reviewing])->save();

    $question = $this->form->questions()->orderBy('sort')->firstOrFail();
    $untouched = $this->form->questions()->orderBy('sort')->skip(1)->firstOrFail();

    app(SaveReviewDraft::class)->handle($submission->fresh(), $reviewer, [$question->ulid => 4]);

    expect($this->form->fresh()?->isLocked())->toBeFalse()
        ->and($this->user->can('delete', $question->fresh()))->toBeFalse()
        // A question nobody has answered is still deletable: the rule is about
        // the answer rows, not about the form.
        ->and($this->user->can('delete', $untouched->fresh()))->toBeTrue();

    reviewQuestionsManager($this->conference->fresh())
        ->assertTableActionHidden('delete', $question)
        ->assertTableActionVisible('delete', $untouched);

    // And the model refuses for every other path - console, the Plan 6 import -
    // exactly as the lock does.
    expect(fn () => $question->fresh()?->delete())->toThrow(ReviewQuestionInUse::class);

    expect(ReviewQuestion::query()->whereKey($question->getKey())->exists())->toBeTrue();
});

it('refuses to reorder questions once the form is locked', function () {
    // Filament reorders with a query-builder UPDATE, which fires no model
    // events, so ReviewQuestion's `updating` guard never runs and
    // ReviewQuestionPolicy::reorder() cannot see the lock either (it gets no
    // record). `reorderable(null)` is the only thing holding this.
    $this->form->forceFill(['locked_at' => now()])->save();
    [$first, $second] = $this->form->questions()->take(2)->get()->all();

    reviewQuestionsManager($this->conference->fresh())
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($first->fresh()?->sort)->toBe(1)
        ->and($second->fresh()?->sort)->toBe(2);
});

it('still appends a question to a locked form', function () {
    $this->form->forceFill(['locked_at' => now()])->save();

    reviewQuestionsManager($this->conference->fresh())
        ->callTableAction('create', data: [
            'prompt' => 'Any additional comment for the committee?',
            'type' => ReviewQuestionType::Text->value,
            'required' => false,
        ])
        ->assertHasNoTableActionErrors();

    // Appended at the end, where a reviewer expects a new question - and where
    // it has to be, because reordering is disabled on a locked form.
    expect($this->form->questions()->count())->toBe(10)
        ->and($this->form->questions()->get()->last()?->prompt)->toBe('Any additional comment for the committee?')
        ->and($this->form->questions()->get()->last()?->sort)->toBe(10);
});

it('does not show or mount the questions of another organization conference', function () {
    $theirConference = withoutTenant(function (): Conference {
        $conference = Conference::factory()->create();
        app(CreateDefaultReviewForm::class)->handle($conference);

        return $conference;
    });

    $mine = $this->form->questions()->first();
    $theirs = $theirConference->reviewForm?->questions()->first();

    reviewQuestionsManager($this->conference)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->mountTableAction('edit', $theirs)
        ->assertActionNotMounted()
        ->mountTableAction('delete', $theirs)
        ->assertActionNotMounted();

    expect(ReviewQuestionsRelationManager::canViewForRecord($theirConference, EditConference::class))->toBeFalse()
        ->and($theirs?->fresh()?->prompt)->toBe($theirs?->prompt);
});
