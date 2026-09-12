<?php

declare(strict_types=1);

use App\Actions\Decisions\ApplyDecision;
use App\Actions\Decisions\ApplyDecisions;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\SubmissionStatus;
use App\Exceptions\DecisionNotAcceptable;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->conference = Conference::factory()->create([
        'status' => ConferenceStatus::Reviewing,
        'review_deadline' => now()->addMonth(),
    ]);
    $this->submission = Submission::factory()->for($this->conference)->submitted()->create();
    $this->submission->forceFill(['reference' => 'AAM26-017'])->save();
});

it('applies a decision, moves the status and appends to the history', function () {
    $result = app(ApplyDecision::class)->handle($this->submission, Decision::AcceptedOral, $this->actor, 'Strong reviews.');

    expect($result->decision)->toBe(Decision::AcceptedOral)
        ->and($result->status)->toBe(SubmissionStatus::Accepted)
        // Prepared quietly: nothing is emailed by deciding.
        ->and($result->decision_notified_at)->toBeNull()
        ->and($result->decisions()->count())->toBe(1);

    $row = $result->currentDecision();

    expect($row?->decision)->toBe(Decision::AcceptedOral)
        ->and($row?->decided_by)->toBe($this->actor->id)
        ->and($row?->decided_at)->not->toBeNull()
        ->and($row?->note)->toBe('Strong reviews.')
        ->and($row?->letter_markdown)->toBeNull()
        ->and(Activity::query()->where('description', 'submission.decided')->count())->toBe(1);
});

it('maps every decision to the right status', function (Decision $decision, SubmissionStatus $status) {
    $result = app(ApplyDecision::class)->handle($this->submission, $decision, $this->actor);

    expect($result->status)->toBe($status);
})->with([
    'oral' => [Decision::AcceptedOral, SubmissionStatus::Accepted],
    'poster' => [Decision::AcceptedPoster, SubmissionStatus::Accepted],
    'waitlisted' => [Decision::Waitlisted, SubmissionStatus::Waitlisted],
    'rejected' => [Decision::Rejected, SubmissionStatus::Rejected],
]);

it('lets an undecided decision be changed as often as the committee likes', function () {
    $apply = app(ApplyDecision::class);

    $apply->handle($this->submission, Decision::AcceptedPoster, $this->actor);
    $apply->handle($this->submission, Decision::Waitlisted, $this->actor);
    $result = $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);

    // Three rows, newest first, and the column agrees with the top one.
    expect($result->decision)->toBe(Decision::AcceptedOral)
        ->and($result->decisions()->count())->toBe(3)
        ->and($result->decisions()->pluck('decision')->all())
        ->toBe([Decision::AcceptedOral, Decision::Waitlisted, Decision::AcceptedPoster]);
});

it('does nothing at all when the decision is already the one asked for', function () {
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);

    $before = $this->submission->fresh()?->currentDecision()?->decided_at;

    $apply->handle($this->submission->fresh() ?? $this->submission, Decision::AcceptedOral, $this->actor);

    // No second history row, no new timestamp, no second activity entry. A
    // bulk decision over a filtered list hits this constantly and it is not an
    // error.
    expect($this->submission->fresh()?->decisions()->count())->toBe(1)
        ->and($this->submission->fresh()?->currentDecision()?->decided_at?->equalTo($before))->toBeTrue()
        ->and(Activity::query()->where('description', 'submission.decided')->count())->toBe(1);
});

it('refuses to change a decision the author has already been told about', function () {
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);
    $this->submission->forceFill(['decision_notified_at' => now()])->save();

    $submission = $this->submission->fresh() ?? $this->submission;

    expect($apply->blockers($submission, Decision::Rejected))->not->toBe([])
        ->and(fn () => $apply->handle($submission, Decision::Rejected, $this->actor))
        ->toThrow(DecisionNotAcceptable::class);

    expect($submission->fresh()?->decision)->toBe(Decision::AcceptedOral);
});

it('refuses a note-only edit on a row the author has already been told about', function () {
    // The guard is about "would this write change anything", not about the
    // decision VALUE. handle() clears decision_notified_at unconditionally, so
    // a same-decision-different-note write on a notified row would put it back
    // in the send queue and the next "Send decision emails" would mail the
    // author a SECOND letter - rotating their status token again, with no
    // change-and-resend modal and no warning. The bulk action is the live path:
    // decideSelected() has no per-row visible() the way decide() does.
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor, 'First note.');
    $this->submission->forceFill(['decision_notified_at' => now()])->save();
    $submission = $this->submission->fresh() ?? $this->submission;

    expect($apply->blockers($submission, Decision::AcceptedOral, note: 'Second note.'))->not->toBe([])
        ->and(fn () => $apply->handle($submission, Decision::AcceptedOral, $this->actor, 'Second note.'))
        ->toThrow(DecisionNotAcceptable::class);

    // The letter stays sent: nothing re-enters the send queue.
    expect($submission->fresh()?->decision_notified_at)->not->toBeNull()
        ->and($submission->fresh()?->currentDecision()?->note)->toBe('First note.');
});

it('still treats an identical re-apply on a notified row as unchanged, not refused', function () {
    // The other half of the same rule, and the reason the guard cannot simply
    // be "notified means refuse": re-applying the same decision with the same
    // note over a filtered list is the normal post-send case, and refusing it
    // is how an organizer learns to ignore the report.
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor, 'First note.');
    $this->submission->forceFill(['decision_notified_at' => now()])->save();
    $submission = $this->submission->fresh() ?? $this->submission;

    expect($apply->blockers($submission, Decision::AcceptedOral, note: 'First note.'))->toBe([])
        ->and($apply->handle($submission, Decision::AcceptedOral, $this->actor, 'First note.')->decision_notified_at)
        ->not->toBeNull()
        ->and($submission->fresh()?->decisions()->count())->toBe(1);
});

it('changes a notified decision when the caller says resend, and queues a new letter', function () {
    $apply = app(ApplyDecision::class);
    $apply->handle($this->submission, Decision::AcceptedOral, $this->actor);
    $this->submission->forceFill(['decision_notified_at' => now()])->save();

    $result = $apply->handle(
        $this->submission->fresh() ?? $this->submission,
        Decision::Rejected,
        $this->actor,
        'Programme was rebuilt after a withdrawal.',
        changeAfterSend: true,
    );

    expect($result->decision)->toBe(Decision::Rejected)
        ->and($result->status)->toBe(SubmissionStatus::Rejected)
        // Back to null, so the next send queues the new letter.
        ->and($result->decision_notified_at)->toBeNull()
        ->and($result->decisions()->count())->toBe(2)
        // The superseded row keeps its own letter columns untouched - that is
        // the audit.
        ->and($result->decisions()->get()->last()?->decision)->toBe(Decision::AcceptedOral);
});

it('refuses a draft, a withdrawal, and a conference that is not deciding', function () {
    $apply = app(ApplyDecision::class);

    $draft = Submission::factory()->for($this->conference)->create();
    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();

    expect($apply->blockers($draft, Decision::AcceptedOral))->not->toBe([])
        ->and($apply->blockers($withdrawn, Decision::AcceptedOral))->not->toBe([])
        ->and(fn () => $apply->handle($draft, Decision::AcceptedOral, $this->actor))
        ->toThrow(DecisionNotAcceptable::class);

    $early = Conference::factory()->closed()->create();
    $tooSoon = Submission::factory()->for($early)->submitted()->create();

    expect($apply->blockers($tooSoon, Decision::AcceptedOral))->not->toBe([]);
});

it('still allows a decision once the conference is decided', function () {
    // A conference in `decided` is one whose letters have gone out. A late
    // correction still has to be possible - a presenter withdraws and the
    // waiting list moves - and it goes through the same change-and-resend rule.
    $this->conference->forceFill(['status' => ConferenceStatus::Decided])->save();

    $result = app(ApplyDecision::class)->handle(
        $this->submission->fresh() ?? $this->submission,
        Decision::AcceptedPoster,
        $this->actor,
    );

    expect($result->decision)->toBe(Decision::AcceptedPoster);
});

it('reports every row of a bulk run, one line each', function () {
    $ok = Submission::factory()->for($this->conference)->submitted()->create();
    $ok->forceFill(['reference' => 'AAM26-002'])->save();

    $already = Submission::factory()->for($this->conference)->decided(Decision::AcceptedOral)->create();
    $already->forceFill(['reference' => 'AAM26-003'])->save();

    $notified = Submission::factory()->for($this->conference)->decided(Decision::Rejected, notified: true)->create();
    $notified->forceFill(['reference' => 'AAM26-004'])->save();

    $withdrawn = Submission::factory()->for($this->conference)->withdrawn()->create();
    $withdrawn->forceFill(['reference' => 'AAM26-005'])->save();

    $report = app(ApplyDecisions::class)->handle(
        [$this->submission, $ok, $already, $notified, $withdrawn],
        Decision::AcceptedOral,
        $this->actor,
    );

    expect($report['applied'])->toBe(2)
        ->and($report['unchanged'])->toBe(1)
        ->and(array_keys($report['refused']))->toBe(['AAM26-004', 'AAM26-005'])
        ->and($report['refused']['AAM26-004'])->toBeArray()->not->toBeEmpty();
});

it('never reports a row it actually wrote as unchanged', function () {
    // The bulk report decides "unchanged" from the decision and the note alone,
    // while ApplyDecision::isUnchanged() additionally requires a current HISTORY
    // row - so a submission whose `decision` column is set with no
    // submission_decisions row behind it (a hand-written UPDATE, or the Plan 6
    // import) really is written: a history row is appended, an activity entry
    // logged and decision_notified_at nulled. Telling the organizer nothing
    // changed is the report disagreeing with what the action did.
    $bare = Submission::factory()->for($this->conference)->submitted()->create();
    $bare->forceFill(['reference' => 'AAM26-009'])->save();

    DB::table('submissions')->where('id', $bare->id)->update([
        'decision' => Decision::AcceptedOral->value,
        'status' => SubmissionStatus::Accepted->value,
    ]);

    expect($bare->fresh()?->decisions()->count())->toBe(0);

    $report = app(ApplyDecisions::class)->handle(
        [$bare->fresh()],
        Decision::AcceptedOral,
        $this->actor,
    );

    expect($report['applied'])->toBe(1)
        ->and($report['unchanged'])->toBe(0)
        ->and($bare->fresh()?->decisions()->count())->toBe(1);
});

it('summarises a bulk run in one sentence', function () {
    // summarise() is what the bulk action actually shows the organizer, and
    // nothing else in this plan exercises it - a wording that dropped the
    // refused references would look fine on screen and tell nobody which rows
    // were skipped.
    $summary = ApplyDecisions::summarise([
        'applied' => 2,
        'unchanged' => 1,
        'refused' => ['AAM26-004' => ['This abstract has already been told.']],
    ]);

    expect($summary)->toBeString()
        ->toContain('2')
        ->toContain('AAM26-004')
        ->toContain('This abstract has already been told.');
});
