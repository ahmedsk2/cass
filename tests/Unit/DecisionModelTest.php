<?php

declare(strict_types=1);

use App\Enums\Decision;
use App\Enums\EmailTemplateKey;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\SubmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps every decision to a status and to a template key', function () {
    // The two mappings are the reason this enum exists. A case added later
    // without both is a row whose status disagrees with its decision, and an
    // email nothing can render.
    expect(Decision::AcceptedOral->submissionStatus())->toBe(SubmissionStatus::Accepted)
        ->and(Decision::AcceptedPoster->submissionStatus())->toBe(SubmissionStatus::Accepted)
        ->and(Decision::Waitlisted->submissionStatus())->toBe(SubmissionStatus::Waitlisted)
        ->and(Decision::Rejected->submissionStatus())->toBe(SubmissionStatus::Rejected)
        ->and(Decision::AcceptedOral->templateKey())->toBe(EmailTemplateKey::DecisionAcceptedOral)
        ->and(Decision::AcceptedPoster->templateKey())->toBe(EmailTemplateKey::DecisionAcceptedPoster)
        ->and(Decision::Waitlisted->templateKey())->toBe(EmailTemplateKey::DecisionWaitlisted)
        ->and(Decision::Rejected->templateKey())->toBe(EmailTemplateKey::DecisionRejected);

    foreach (Decision::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBe('')
            ->and($case->getColor())->toBeString()->not->toBe('')
            // Every case must be covered by the two maps above, whatever is
            // added later: a match() with no default throws UnhandledMatchError
            // here rather than in an organizer's face.
            ->and($case->submissionStatus())->toBeInstanceOf(SubmissionStatus::class)
            ->and($case->templateKey())->toBeInstanceOf(EmailTemplateKey::class);
    }

    expect(Decision::AcceptedOral->isAccepted())->toBeTrue()
        ->and(Decision::AcceptedPoster->isAccepted())->toBeTrue()
        ->and(Decision::Waitlisted->isAccepted())->toBeFalse()
        ->and(Decision::Rejected->isAccepted())->toBeFalse();
});

it('reports the four decisions in one fixed order, wherever they are printed', function () {
    // inReportOrder() exists for exactly one reason: reordering the enum's own
    // cases must not silently reorder the ranking summary strip, the
    // send-emails modal, the decide radio group, the conference infolist and
    // the admin list - eight call sites, all of which foreach it. No assertion
    // named the sequence, so replacing the body with cases(), or any
    // permutation of it, kept the suite green and the guard it documents did
    // not exist.
    expect(array_map(static fn (Decision $decision): string => $decision->value, Decision::inReportOrder()))
        ->toBe(['accepted_oral', 'accepted_poster', 'waitlisted', 'rejected'])
        // Best news first, worst last - and every case in it exactly once, so a
        // fifth decision added later cannot be quietly left off the screens.
        ->and(Decision::inReportOrder())->toHaveCount(count(Decision::cases()));
});

it('keeps the whole decision history and names the current one', function () {
    $submission = Submission::factory()->submitted()->create();
    $actor = User::factory()->create();

    $first = SubmissionDecision::factory()->for($submission)->create([
        'decision' => Decision::Waitlisted,
        'decided_by' => $actor->id,
        'decided_at' => now()->subDay(),
    ]);
    $second = SubmissionDecision::factory()->for($submission)->create([
        'decision' => Decision::AcceptedOral,
        'decided_by' => $actor->id,
        'decided_at' => now(),
    ]);

    expect($submission->decisions()->count())->toBe(2)
        // Newest first: the history is read top-down by a human.
        ->and($submission->decisions()->first()?->is($second))->toBeTrue()
        ->and($submission->currentDecision()?->is($second))->toBeTrue()
        ->and($first->fresh()?->decision)->toBe(Decision::Waitlisted);
});

it('refuses mass assignment on the decision history', function () {
    // Every column is written by ApplyDecision or SendDecisionEmails with
    // forceFill(). A fillable `decision` is a form field that decides.
    expect(fn () => new SubmissionDecision(['decision' => Decision::AcceptedOral->value]))
        ->toThrow(MassAssignmentException::class);
});

it('restricts deleting a submission that has a decision', function () {
    $submission = Submission::factory()->submitted()->create();
    SubmissionDecision::factory()->for($submission)->create();

    // Soft delete is fine - that is what the organizer panel does.
    $submission->delete();
    expect(Submission::withTrashed()->whereKey($submission->getKey())->exists())->toBeTrue();

    // A hard delete is not: the decision is evidence, and Plan 6's purge has to
    // remove it deliberately in application code. SQLite enforces this only
    // with foreign keys on, which config/database.php's `foreign_key_constraints`
    // leaves on by default for the suite's connection.
    expect(fn () => $submission->forceDelete())->toThrow(QueryException::class);
});

it('starts every submission unscored and undecided', function () {
    // fresh(), not the in-memory model: the four score columns have database
    // defaults the factory deliberately does not set, so an unrefreshed model
    // answers null for review_count rather than the 0 the column holds.
    $submission = Submission::factory()->submitted()->create()->fresh();

    expect($submission?->score)->toBeNull()
        ->and($submission?->score_spread)->toBeNull()
        ->and($submission?->review_count)->toBe(0)
        ->and($submission?->scored_at)->toBeNull()
        ->and($submission?->decision)->toBeNull()
        ->and($submission?->decision_notified_at)->toBeNull()
        ->and($submission?->currentDecision())->toBeNull()
        ->and($submission?->decisionLetter())->toBeNull();
});

it('casts the two score columns to two decimal places on every driver', function () {
    // Laravel gives a decimal column NUMERIC affinity on SQLite, which returns
    // int(72)/float(72.5), while MySQL returns "72.00"/"72.50". The cast makes
    // both a two-decimal string - the same reason review_questions.weight is
    // cast - so a test written here passes in Task 11's MySQL run too.
    $submission = Submission::factory()->submitted()->create();
    $submission->forceFill(['score' => 72.5, 'score_spread' => 4, 'review_count' => 3, 'scored_at' => now()])->save();

    expect($submission->fresh()?->score)->toBe('72.50')
        ->and($submission->fresh()?->score_spread)->toBe('4.00')
        ->and($submission->fresh()?->review_count)->toBe(3)
        ->and($submission->fresh()?->scored_at)->not->toBeNull();
});

it('shows a letter only once the decision has been notified', function () {
    $submission = Submission::factory()->submitted()->create();
    $decision = SubmissionDecision::factory()->for($submission)->create([
        'decision' => Decision::AcceptedOral,
        'letter_subject' => 'AAM26-017 accepted for oral presentation',
        'letter_markdown' => 'Dear Dr Sara Al-Harbi, we are pleased...',
        'notified_at' => null,
    ]);

    // The letter exists on the row but the submission has not been notified:
    // the page must show nothing. Two conditions, both required, because a
    // half-finished send must not leak a letter.
    expect($submission->decisionLetter())->toBeNull();

    $submission->forceFill(['decision' => Decision::AcceptedOral, 'decision_notified_at' => now()])->save();
    $decision->forceFill(['notified_at' => now()])->save();

    expect($submission->fresh()?->decisionLetter()?->is($decision))->toBeTrue();
});
