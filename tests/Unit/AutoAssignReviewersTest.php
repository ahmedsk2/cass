<?php

declare(strict_types=1);

use App\Actions\Reviews\AutoAssignReviewers;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewMode;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Closed,
        'review_mode' => ReviewMode::Assigned,
        'reviewers_per_submission' => 2,
    ]);
    $this->actor = User::factory()->create();
});

/** @return list<ConferenceReviewer> */
function makeReviewers(Conference $conference, int $count, string $domain = 'reviewers.example'): array
{
    $reviewers = [];

    for ($i = 1; $i <= $count; $i++) {
        $user = User::factory()->create([
            'name' => 'Reviewer '.$i,
            'email' => 'reviewer'.$i.'@'.$domain,
        ]);
        $reviewers[] = ConferenceReviewer::factory()->for($conference)->create(['user_id' => $user->id]);
    }

    return $reviewers;
}

/** @return list<Submission> */
function makeSubmissions(Conference $conference, int $count): array
{
    $submissions = [];

    for ($i = 1; $i <= $count; $i++) {
        $submission = Submission::factory()->for($conference)->submitted()->create(['title' => 'Abstract '.$i]);
        // Pin the reference. AssignmentPlan labels a row and a shortfall with
        // `$submission->reference ?? $submission->title`, and
        // SubmissionFactory::submitted() always sets a random reference
        // (database/factories/SubmissionFactory.php:44-50) - so without this,
        // every assertion on "Abstract 1" or on a title is asserting against a
        // string nobody ever prints.
        $submission->forceFill(['reference' => 'AAM26-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)])->save();
        SubmissionAuthor::factory()->for($submission)->corresponding()->create([
            'name' => 'Author '.$i,
            'email' => 'author'.$i.'@authors.example',
            'affiliation' => 'Authors Hospital',
            'sort' => 1,
        ]);
        $submissions[] = $submission->refresh();
    }

    return $submissions;
}

it('gives every abstract its target and keeps the loads within one of each other', function () {
    makeReviewers($this->conference, 3);
    makeSubmissions($this->conference, 6);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    // 6 abstracts x 2 reviewers = 12 assignments over 3 reviewers = 4 each.
    expect($plan->assignments)->toBe(12)
        ->and($plan->shortfalls)->toBe([])
        ->and(max($plan->loads))->toBe(4)
        ->and(min($plan->loads))->toBe(4)
        ->and(ReviewAssignment::query()->count())->toBe(12);

    foreach ($this->conference->submissions as $submission) {
        expect($submission->reviewAssignments()->count())->toBe(2)
            // Never the same reviewer twice on one abstract.
            ->and($submission->reviewAssignments()->distinct('reviewer_user_id')->count('reviewer_user_id'))->toBe(2);
    }
});

it('produces the same plan every time it is asked', function () {
    makeReviewers($this->conference, 4);
    makeSubmissions($this->conference, 5);

    $action = app(AutoAssignReviewers::class);

    $first = $action->plan($this->conference);
    $second = $action->plan($this->conference);

    expect(array_column($first->rows, 'add'))->toBe(array_column($second->rows, 'add'))
        // plan() is pure: asking twice must not have written anything.
        ->and(ReviewAssignment::query()->count())->toBe(0);
});

it('counts the assignments an organizer already made by hand and tops up around them', function () {
    $reviewers = makeReviewers($this->conference, 3);
    $submissions = makeSubmissions($this->conference, 3);

    ReviewAssignment::factory()->for($submissions[0])->create(['reviewer_user_id' => $reviewers[0]->user_id]);
    ReviewAssignment::factory()->for($submissions[1])->create(['reviewer_user_id' => $reviewers[0]->user_id]);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    // Reviewer 1 starts two ahead, so the balance work goes to the other two.
    expect($plan->loads[$reviewers[0]->user_id])->toBe(2)
        ->and(ReviewAssignment::query()->count())->toBe(6);

    foreach ($submissions as $submission) {
        expect($submission->reviewAssignments()->count())->toBe(2);
    }
});

it('skips a reviewer whose email domain matches an author, and does not skip a free mailbox', function () {
    $institutional = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'omar@authors.example'])->id,
    ]);
    $gmailReviewer = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'sara@gmail.com'])->id,
    ]);
    $clean = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'noor@reviewers.example'])->id,
    ]);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'author@authors.example', 'affiliation' => null, 'sort' => 1,
    ]);
    SubmissionAuthor::factory()->for($submission)->create([
        'email' => 'coauthor@gmail.com', 'affiliation' => null, 'sort' => 2,
    ]);

    expect(AutoAssignReviewers::conflicts($institutional, $submission))->toBeTrue()
        // "both on gmail" is an artefact of free mailboxes, not a conflict -
        // see config('cass.review.free_email_domains').
        ->and(AutoAssignReviewers::conflicts($gmailReviewer, $submission))->toBeFalse()
        ->and(AutoAssignReviewers::conflicts($clean, $submission))->toBeFalse();

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    expect($submission->reviewAssignments()->pluck('reviewer_user_id')->sort()->values()->all())
        ->toBe(collect([$gmailReviewer->user_id, $clean->user_id])->sort()->values()->all());
});

it('skips an exact email match even on a free mailbox', function () {
    $sameAddress = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'author@gmail.com'])->id,
    ]);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'Author@Gmail.com', 'affiliation' => null, 'sort' => 1,
    ]);

    expect(AutoAssignReviewers::conflicts($sameAddress, $submission))->toBeTrue();
});

it('skips a reviewer whose affiliation matches an author, whatever the punctuation', function () {
    $sameHospital = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'omar@reviewers.example'])->id,
        'affiliation' => '  king faisal specialist hospital ',
    ]);
    $elsewhere = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'sara@reviewers.example'])->id,
        'affiliation' => 'Somewhere Else',
    ]);
    $unknown = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => User::factory()->create(['email' => 'noor@reviewers.example'])->id,
        'affiliation' => null,
    ]);

    $submission = Submission::factory()->for($this->conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'author@authors.example',
        'affiliation' => 'King Faisal Specialist Hospital.',
        'sort' => 1,
    ]);

    expect(AutoAssignReviewers::conflicts($sameHospital, $submission))->toBeTrue()
        ->and(AutoAssignReviewers::conflicts($elsewhere, $submission))->toBeFalse()
        // No affiliation on file never matches: that is why Task 4 asks for one.
        ->and(AutoAssignReviewers::conflicts($unknown, $submission))->toBeFalse();
});

it('does not treat organization membership as a conflict', function () {
    // Decided: for a small society the programme chair reviews too, and
    // treating membership as a conflict empties the pool of the people who know
    // the field. The real conflict membership sometimes proxies for - working
    // where the author works - is caught by the domain and affiliation rules.
    $member = User::factory()->create(['email' => 'chair@reviewers.example']);
    $this->conference->organization->addMember($member, OrganizationRole::Owner);
    $reviewer = ConferenceReviewer::factory()->for($this->conference)->create(['user_id' => $member->id]);

    $submission = makeSubmissions($this->conference, 1)[0];

    expect(AutoAssignReviewers::conflicts($reviewer, $submission))->toBeFalse();
});

it('names every abstract it could not fill instead of leaving it short in silence', function () {
    $this->conference->forceFill(['reviewers_per_submission' => 3])->save();
    makeReviewers($this->conference, 2);
    $submissions = makeSubmissions($this->conference, 2);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference->fresh(), $this->actor);

    expect($plan->shortfalls)->toHaveCount(2)
        // By reference, not title: AssignmentPlan labels a shortfall
        // `$submission->reference ?? $submission->title`, and makeSubmissions()
        // pins the reference for exactly this assertion.
        ->and(implode(' ', $plan->shortfalls))->toContain('AAM26-001')
        ->and($submissions[0]->reviewAssignments()->count())->toBe(2);
});

it('plans nothing at all when there are no reviewers', function () {
    makeSubmissions($this->conference, 2);

    $plan = app(AutoAssignReviewers::class)->plan($this->conference);

    expect($plan->assignments)->toBe(0)
        ->and($plan->rows)->toBe([])
        ->and($plan->shortfalls)->toHaveCount(2);
});

it('ignores drafts, withdrawals and removed reviewers', function () {
    $active = makeReviewers($this->conference, 1)[0];
    ConferenceReviewer::factory()->for($this->conference)->removed()->create();

    $submitted = makeSubmissions($this->conference, 1)[0];
    Submission::factory()->for($this->conference)->create(['title' => 'Draft']);
    Submission::factory()->for($this->conference)->withdrawn()->create(['title' => 'Withdrawn']);

    $plan = app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    expect($plan->assignments)->toBe(1)
        ->and(ReviewAssignment::query()->pluck('submission_id')->all())->toBe([$submitted->id])
        ->and(ReviewAssignment::query()->pluck('reviewer_user_id')->all())->toBe([$active->user_id]);
});

it('writes one activity entry per submission it changed', function () {
    makeReviewers($this->conference, 2);
    makeSubmissions($this->conference, 3);

    app(AutoAssignReviewers::class)->apply($this->conference, $this->actor);

    expect(Activity::query()->where('description', 'review.assignments_changed')->count())->toBe(3)
        ->and(Activity::query()->where('description', 'review.auto_assigned')->count())->toBe(1);
});
