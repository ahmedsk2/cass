<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewStatus;
use App\Filament\Admin\Resources\Conferences\Pages\ViewConference;
use App\Filament\Admin\Resources\Conferences\RelationManagers\ReviewersRelationManager;
use App\Filament\Admin\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Admin\Resources\Organizations\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\Organizations\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Reviews\ReviewResource;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Submissions\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Submissions\RelationManagers\ReviewsRelationManager;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->admin = User::factory()->platformAdmin()->create();
    actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::bootCurrentPanel();

    $this->organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $this->conference = Conference::factory()->for($this->organization)->create([
        'name' => 'Alpha Annual Meeting',
        'status' => ConferenceStatus::Reviewing,
    ]);
    $this->form = ReviewForm::factory()->for($this->conference)->create(['is_active' => true]);
    // `prompt`, not `question`: review_questions has no `question` column
    // (2026_09_11_000500_create_review_questions_table.php:18), and the plan's
    // name for it is the legacy schema's, not this one's.
    $this->question = ReviewQuestion::factory()->for($this->form)->create([
        'prompt' => 'Is the methodology sound?',
        'scale_min' => 1,
        'scale_max' => 5,
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create([
        'title' => 'Early mobilisation after cardiac surgery',
    ]);

    $this->reviewer = User::factory()->create(['name' => 'Dr Salah Almubarak', 'email' => 'salah@example.org']);
    ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $this->reviewer->getKey()]);

    $this->review = Review::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $this->reviewer->getKey(),
        'review_form_id' => $this->form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    (new ReviewAnswer)->forceFill([
        'review_id' => $this->review->getKey(),
        'review_question_id' => $this->question->getKey(),
        'value_int' => 4,
        'value_text' => 'The sample size is small but the design is sound.',
        'value_bool' => null,
        'choice_key' => null,
    ])->save();
});

it('lists every review across every conference', function () {
    $theirs = withoutTenant(function (): Review {
        $conference = Conference::factory()->create();
        $form = ReviewForm::factory()->for($conference)->create(['is_active' => true]);
        $submission = Submission::factory()->for($conference)->submitted()->create();

        return Review::factory()->create([
            'submission_id' => $submission->getKey(),
            'reviewer_user_id' => User::factory()->create()->getKey(),
            'review_form_id' => $form->getKey(),
            'status' => ReviewStatus::Submitted,
            'submitted_at' => now(),
        ]);
    });

    livewire(ListReviews::class)
        ->assertCanSeeTableRecords([$this->review, $theirs])
        ->assertCanRenderTableColumn('submission.title')
        ->assertCanRenderTableColumn('reviewer.name')
        ->assertCanRenderTableColumn('score')
        ->assertSee('Dr Salah Almubarak');
});

it('prints what a reviewer actually wrote — the gap Plan 5 recorded', function () {
    get(ReviewResource::getUrl('view', ['record' => $this->review], panel: 'admin'))
        ->assertOk()
        ->assertSee('Early mobilisation after cardiac surgery')
        ->assertSee('Dr Salah Almubarak')
        // The question text and the answer, which no organizer or admin screen
        // has ever shown.
        ->assertSee('Is the methodology sound?')
        ->assertSee('The sample size is small but the design is sound.')
        // ReviewInfolist::answerText() renders a Likert answer as "4 / 5" (the
        // value and the question's scale_max). A bare '4' would match a ULID, a
        // date or Filament's own markup and prove nothing.
        ->assertSee('4 / 5');
});

it('hangs reviews and assignments off the submission, and reviewers off the conference', function () {
    ReviewAssignment::factory()->create([
        'submission_id' => $this->submission->getKey(),
        'reviewer_user_id' => $this->reviewer->getKey(),
    ]);

    livewire(ReviewsRelationManager::class, [
        'ownerRecord' => $this->submission,
        'pageClass' => ViewSubmission::class,
    ])->assertCanSeeTableRecords([$this->review]);

    livewire(AssignmentsRelationManager::class, [
        'ownerRecord' => $this->submission,
        'pageClass' => ViewSubmission::class,
    ])->assertSee('Dr Salah Almubarak');

    livewire(ReviewersRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => ViewConference::class,
    ])->assertSee('salah@example.org');
});

it('hangs members and invitations off the organization', function () {
    $owner = User::factory()->create(['name' => 'Basmalah Alabduljabbar']);
    $this->organization->addMember($owner, OrganizationRole::Owner);
    OrganizationInvitation::factory()->for($this->organization)->create(['email' => 'pending@example.org']);

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $this->organization,
        'pageClass' => ViewOrganization::class,
    ])->assertSee('Basmalah Alabduljabbar')->assertSee(OrganizationRole::Owner->getLabel());

    livewire(InvitationsRelationManager::class, [
        'ownerRecord' => $this->organization,
        'pageClass' => ViewOrganization::class,
    ])->assertSee('pending@example.org');
});

it('offers nothing that writes on any of the five relation managers or the review list', function (string $manager, string $owner, string $page) {
    $table = livewire($manager, ['ownerRecord' => $this->{$owner}, 'pageClass' => $page])
        ->instance()
        ->getTable();

    $names = array_merge(
        array_keys($table->getFlatActions()),
        array_keys($table->getFlatBulkActions()),
    );

    // RelationManager adds CreateAction, EditAction and DeleteAction by
    // default, and every one of these parents' policies answers true for a
    // platform admin through before() - so the refusal is the class, not the
    // policy.
    expect(array_diff($names, ['view']))->toBe([]);
})->with([
    [ReviewsRelationManager::class, 'submission', ViewSubmission::class],
    [AssignmentsRelationManager::class, 'submission', ViewSubmission::class],
    [ReviewersRelationManager::class, 'conference', ViewConference::class],
    [MembersRelationManager::class, 'organization', ViewOrganization::class],
    [InvitationsRelationManager::class, 'organization', ViewOrganization::class],
]);

it('refuses the review list to an organization owner', function () {
    $owner = User::factory()->create();
    $this->organization->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get(ReviewResource::getUrl('index', panel: 'admin'))->assertForbidden();
    actingAs($owner)->get(ReviewResource::getUrl('view', ['record' => $this->review], panel: 'admin'))->assertForbidden();
});

it('registers exactly two review routes', function () {
    expect(array_keys(ReviewResource::getPages()))->toBe(['index', 'view']);
});
