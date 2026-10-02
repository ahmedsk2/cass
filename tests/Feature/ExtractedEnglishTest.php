<?php

declare(strict_types=1);

use App\Actions\Conferences\PublishConference;
use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Enums\ConferenceStatus;
use App\Enums\CustomFieldType;
use App\Enums\EmailLogStatus;
use App\Enums\EmailTemplateKey;
use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PosterSize;
use App\Enums\PresentationPreference;
use App\Enums\ReminderThreshold;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\Submission;
use App\Support\Scoring\RankingRows;

/**
 * Plan 7 Task 2 moved every string below into lang/en without rewording one,
 * and these tests are the proof. They were written against the code BEFORE
 * the move and passed there, and they pass after it because the English is
 * byte for byte the same. That is why none of them failed first: a test that
 * had to fail before the change could not also prove the change altered
 * nothing.
 *
 * The literals are copied from the code as it was, never from lang/en - a
 * test that read its expectation from the language file would pass whatever
 * the file said.
 */
it('reads every enum label in the same english as before the sweep', function (string $enum, array $labels) {
    $actual = [];

    foreach ($enum::cases() as $case) {
        $actual[$case->value] = $case->getLabel();
    }

    expect($actual)->toBe($labels);
})->with([
    'conference status' => [ConferenceStatus::class, [
        'draft' => 'Draft',
        'open' => 'Open for submissions',
        'closed' => 'Submissions closed',
        'reviewing' => 'Under review',
        'decided' => 'Decisions sent',
        'archived' => 'Archived',
    ]],
    'custom field type' => [CustomFieldType::class, [
        'text' => 'Single line of text',
        'textarea' => 'Paragraph',
        'select' => 'Choose one from a list',
        'checkbox' => 'Yes / no checkbox',
        'number' => 'Number',
    ]],
    'email log status' => [EmailLogStatus::class, [
        'queued' => 'Queued',
        'sent' => 'Sent',
        'failed' => 'Failed',
    ]],
    'email template key' => [EmailTemplateKey::class, [
        'submission_received' => 'Abstract received',
        'submission_draft_saved' => 'Draft saved',
        'reviewer_invitation' => 'Reviewer invitation',
        'reviewer_reminder' => 'Reviewer reminder',
        'reviewer_overdue' => 'Reviewer overdue',
        'decision_accepted_oral' => 'Decision: accepted for oral presentation',
        'decision_accepted_poster' => 'Decision: accepted for poster',
        'decision_waitlisted' => 'Decision: waitlisted',
        'decision_rejected' => 'Decision: not accepted',
        'organization_approved' => 'Organization approved',
        'organization_rejected' => 'Organization rejected',
        'organization_suspended' => 'Organization suspended',
    ]],
    'invitation status' => [InvitationStatus::class, [
        'pending' => 'Invited',
        'accepted' => 'Accepted',
        'expired' => 'Expired',
        'revoked' => 'Withdrawn',
    ]],
    // These two were ucfirst($this->value). The lookup has to give the same
    // three words, not a better phrasing of them.
    'organization role' => [OrganizationRole::class, [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
    ]],
    'organization status' => [OrganizationStatus::class, [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'suspended' => 'Suspended',
    ]],
    'organization type' => [OrganizationType::class, [
        'society' => 'Scientific society or association',
        'hospital' => 'Hospital or health cluster',
        'university' => 'University or college',
        'company' => 'Company or agency',
        'other' => 'Other',
    ]],
    'poster size' => [PosterSize::class, [
        'a4' => 'A4 poster (210 x 297 mm)',
        'a3' => 'A3 poster (297 x 420 mm)',
    ]],
    'presentation preference' => [PresentationPreference::class, [
        'oral' => 'Oral presentation',
        'poster' => 'Poster',
        'either' => 'Either is fine',
    ]],
    'reminder threshold' => [ReminderThreshold::class, [
        'days_7' => '7 days before the deadline',
        'days_3' => '3 days before the deadline',
        'days_1' => '1 day before the deadline',
        'overdue' => 'After the deadline',
    ]],
    'review mode' => [ReviewMode::class, [
        'open_pool' => 'Open pool - every reviewer sees every abstract',
        'assigned' => 'Assigned - each abstract goes to named reviewers',
    ]],
    'review question type' => [ReviewQuestionType::class, [
        'likert' => 'Rating scale',
        'text' => 'Free text comment',
        'boolean' => 'Yes / no',
        'select' => 'Choose one from a list',
    ]],
    'review status' => [ReviewStatus::class, [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
    ]],
    'reviewer status' => [ReviewerStatus::class, [
        'active' => 'Active',
        'removed' => 'Removed',
    ]],
    'submission status' => [SubmissionStatus::class, [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'withdrawn' => 'Withdrawn',
        'under_review' => 'Under review',
        'accepted' => 'Accepted',
        'rejected' => 'Not accepted',
        'waitlisted' => 'Waitlisted',
    ]],
]);

it('writes the ranking export headings in the same english as before the sweep', function () {
    // One list for the CSV and the XLSX alike (ExportRankingCsv and
    // ExportRankingXlsx both call this), so pinning it pins both files.
    expect(RankingRows::headers())->toBe([
        'Reference', 'Title', 'Track', 'Presentation preference',
        'Score', 'Spread', 'Reviews',
        'Status', 'Decision', 'Decision letter sent',
        'Corresponding author', 'Corresponding email', 'All authors',
        'Submitted at',
    ]);
});

it('writes the submission list export in the same english as before the sweep', function () {
    // The streamed file itself, not a constant: the heading row is what an
    // organizer's spreadsheet shows, and the yes/no of a checkbox answer is
    // the one lower-case English word inside a data cell. Keys in MySQL's
    // order: a json column hands an object back shorter key first, where
    // SQLite keeps the order written, and the cell follows it.
    $conference = Conference::factory()->create();
    $submission = Submission::factory()->for($conference)->submitted()->create([
        'custom_field_values' => ['first_time' => false, 'needs_projector' => true],
    ]);

    $response = app(ExportSubmissionsCsv::class)
        ->handle(Submission::query()->whereKey($submission->getKey()), 'submissions.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        preg_split('/\r?\n/', trim(substr($csv, 3))) ?: [],
    );

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($rows[0])->toBe([
            'Reference', 'Conference', 'Title', 'Status', 'Track', 'Presentation preference',
            'Corresponding author', 'Corresponding email', 'All authors', 'Affiliations',
            'Contact phone', 'Word count', 'Files', 'Extra answers', 'Submitted at', 'Last edited at',
        ])
        ->and($rows[1][13])->toBe('first_time: no; needs_projector: yes');
});

it('says why a conference cannot be opened in the same english as before the sweep', function (ConferenceStatus $status, string $sentence) {
    // The eighth blocker, and the only one tests/Unit/PublishConferenceTest.php
    // never asserted: the other seven are pinned there, word for word, and
    // must keep passing unedited. The awkward "that is decisions sent" is
    // today's output, kept on purpose - this task moves words, it does not
    // improve them.
    $conference = Conference::factory()
        ->for(Organization::factory()->approved())
        ->withSubmissionWindow()
        ->create(['status' => $status]);

    expect(app(PublishConference::class)->blockers($conference))->toContain($sentence);
})->with([
    'open' => [ConferenceStatus::Open, 'A conference that is open for submissions cannot be opened for submissions.'],
    'reviewing' => [ConferenceStatus::Reviewing, 'A conference that is under review cannot be opened for submissions.'],
    'decided' => [ConferenceStatus::Decided, 'A conference that is decisions sent cannot be opened for submissions.'],
]);
