<?php

declare(strict_types=1);

use App\Actions\Reviewers\UpdateReviewerAffiliation;
use App\Actions\Reviews\AutoAssignReviewers;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Exceptions\MemberChangeRefused;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->conference = Conference::factory()->for($this->organization)->create([
        'status' => ConferenceStatus::Closed,
        'review_mode' => ReviewMode::Assigned,
        'reviewers_per_submission' => 1,
    ]);
    // An explicit address on a domain no author uses, so the only conflict
    // the auto-assign cases below can find is the affiliation one.
    $this->reviewer = User::factory()->create(['email' => 'omar@reviewers.example']);
    $this->row = ConferenceReviewer::factory()->for($this->conference)->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);
});

/**
 * One submitted abstract by one author at $affiliation. A distinct name from
 * AutoAssignReviewersTest's makeSubmissions(): Pest loads every test file into
 * one process, so a second global function of the same name is a fatal error
 * on the full run.
 */
function affiliationSubmission(Conference $conference, string $affiliation): Submission
{
    $submission = Submission::factory()->for($conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->corresponding()->create([
        'email' => 'author@authors.example',
        'affiliation' => $affiliation,
        'sort' => 1,
    ]);

    return $submission->refresh();
}

it('changes the reviewer\'s own affiliation for one conference, trimmed, and logs it with them as causer', function () {
    // The same person reviewing a second conference: the value is per
    // conference, so that row must not move.
    $elsewhere = ConferenceReviewer::factory()->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    app(UpdateReviewerAffiliation::class)->handle($this->row, '  New Hospital  ', $this->reviewer);

    expect($this->row->fresh()?->affiliation)->toBe('New Hospital')
        ->and($elsewhere->fresh()?->affiliation)->toBe('Old Hospital');

    $entry = Activity::query()->where('description', 'reviewer.affiliation_changed')->sole();

    expect($entry->causer_id)->toBe($this->reviewer->id)
        ->and($entry->subject_type)->toBe(ConferenceReviewer::class)
        ->and($entry->subject_id)->toBe($this->row->id)
        // toEqual, not toBe: activity_log.properties is a json column, and
        // MySQL hands an object back shorter key first.
        ->and($entry->properties->all())->toEqual([
            'conference_id' => $this->conference->id,
            'from' => 'Old Hospital',
            'to' => 'New Hospital',
        ]);
});

it('clears the affiliation when the reviewer empties it', function () {
    app(UpdateReviewerAffiliation::class)->handle($this->row, '   ', $this->reviewer);

    expect($this->row->fresh()?->affiliation)->toBeNull()
        ->and(Activity::query()->where('description', 'reviewer.affiliation_changed')->sole()->properties->all())
        ->toMatchArray(['from' => 'Old Hospital', 'to' => null]);
});

it('writes and logs nothing when the trimmed value is the one already stored', function () {
    app(UpdateReviewerAffiliation::class)->handle($this->row, ' Old Hospital ', $this->reviewer);

    expect(Activity::query()->where('description', 'reviewer.affiliation_changed')->count())->toBe(0);
});

it('refuses another reviewer\'s row in the same conference', function () {
    $theirs = ConferenceReviewer::factory()->for($this->conference)->create(['affiliation' => 'Their Hospital']);

    expect(fn () => app(UpdateReviewerAffiliation::class)->handle($theirs, 'Hijacked', $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_not_yours'));

    expect($theirs->fresh()?->affiliation)->toBe('Their Hospital')
        ->and(Activity::query()->where('description', 'reviewer.affiliation_changed')->count())->toBe(0);
});

it('refuses an organizer of the conference, whom the policy\'s update ability does admit', function () {
    // ConferenceReviewerPolicy::update() is "remove reviewer" and answers true
    // for every member of the organization. The new ability must not be that
    // one: the affiliation is the reviewer's own statement about themselves.
    $organizer = User::factory()->create();
    $this->organization->addMember($organizer, OrganizationRole::Owner);

    expect($organizer->can('update', $this->row))->toBeTrue()
        ->and(fn () => app(UpdateReviewerAffiliation::class)->handle($this->row, 'Organizer Typed', $organizer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_not_yours'));

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('refuses a reviewer who has been removed from that conference, even while they review another', function () {
    $this->row->forceFill(['status' => ReviewerStatus::Removed, 'removed_at' => now()])->save();
    ConferenceReviewer::factory()->create(['user_id' => $this->reviewer->id]);

    expect($this->reviewer->isActiveReviewer())->toBeTrue()
        ->and(fn () => app(UpdateReviewerAffiliation::class)->handle($this->row, 'New Hospital', $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_removed'));

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');
});

it('refuses their own row in another organization\'s conference they were removed from', function () {
    $theirOrganization = Organization::factory()->approved()->create();
    $theirConference = Conference::factory()->for($theirOrganization)->closed()->create();
    $removedThere = ConferenceReviewer::factory()->for($theirConference)->removed()->create([
        'user_id' => $this->reviewer->id,
        'affiliation' => 'Old Hospital',
    ]);

    expect(fn () => app(UpdateReviewerAffiliation::class)->handle($removedThere, 'New Hospital', $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_removed'));

    expect($removedThere->fresh()?->affiliation)->toBe('Old Hospital');
});

it('takes 255 characters and refuses 256, the limit the organizer\'s invite form uses', function () {
    expect(fn () => app(UpdateReviewerAffiliation::class)->handle($this->row, str_repeat('a', 256), $this->reviewer))
        ->toThrow(MemberChangeRefused::class, __('reviewer.errors.affiliation_too_long', ['max' => 255]));

    expect($this->row->fresh()?->affiliation)->toBe('Old Hospital');

    app(UpdateReviewerAffiliation::class)->handle($this->row, str_repeat('a', 255), $this->reviewer);

    expect($this->row->fresh()?->affiliation)->toBe(str_repeat('a', 255));
});

it('is the value the auto-assign conflict check reads', function () {
    $submission = affiliationSubmission($this->conference, 'King Faisal Specialist Hospital');

    // Before: no shared institution, so the one reviewer is planned.
    $before = app(AutoAssignReviewers::class)->plan($this->conference);

    expect($before->rows)->toHaveCount(1)
        ->and($before->rows[0]['add'])->toBe([$this->reviewer->id]);

    // After: the same institution, typed the way people type it - lower case,
    // a trailing full stop. AutoAssignReviewers::normalise() makes those equal.
    app(UpdateReviewerAffiliation::class)->handle($this->row, 'king faisal specialist hospital.', $this->reviewer);

    $after = app(AutoAssignReviewers::class)->plan($this->conference);

    $fresh = ConferenceReviewer::query()->with('user')->findOrFail($this->row->id);

    expect(AutoAssignReviewers::conflicts($fresh, $submission))->toBeTrue()
        ->and($after->rows)->toBe([])
        ->and($after->shortfalls)->toHaveCount(1);
});

it('leaves an existing assignment where it is when the new affiliation conflicts with it', function () {
    $submission = affiliationSubmission($this->conference, 'King Faisal Specialist Hospital');
    ReviewAssignment::factory()->create([
        'submission_id' => $submission->id,
        'reviewer_user_id' => $this->reviewer->id,
    ]);

    app(UpdateReviewerAffiliation::class)->handle($this->row, 'King Faisal Specialist Hospital', $this->reviewer);

    // Current behaviour, kept on purpose: nothing is unassigned automatically.
    // Whether the organizer should be told is an owner decision this task
    // records rather than builds.
    expect(ReviewAssignment::query()
        ->where('submission_id', $submission->id)
        ->where('reviewer_user_id', $this->reviewer->id)
        ->exists())->toBeTrue()
        ->and(app(AutoAssignReviewers::class)->plan($this->conference)->rows)->toBe([]);
});
