<?php

declare(strict_types=1);

use App\Actions\Conferences\PurgeConference;
use App\Actions\Organizations\PurgeOrganization;
use App\Enums\ConferenceStatus;
use App\Enums\Decision;
use App\Enums\ReviewStatus;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\LegacyImport;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewerInvitation;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\ShortLink;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/**
 * One conference carrying a row in every table the purge walks. Returns it.
 *
 * The locked review form is the point: ReviewQuestion::deleting throws
 * ReviewFormLocked for a locked form even after every answer is gone, and that
 * is the single most likely way a first draft of this action blows up.
 */
function conferenceWithEverything(?Organization $organization = null): Conference
{
    $conference = Conference::factory()
        ->for($organization ?? Organization::factory()->approved()->create())
        ->create(['status' => ConferenceStatus::Reviewing]);

    $form = ReviewForm::factory()->for($conference)->create(['is_active' => true]);
    $question = ReviewQuestion::factory()->for($form)->create(['scale_min' => 1, 'scale_max' => 5]);
    Track::factory()->for($conference)->create();
    CustomField::factory()->for($conference)->create();
    EmailTemplate::factory()->for($conference)->create();
    ReviewerInvitation::factory()->for($conference)->create();
    ShortLink::forTarget($conference);

    $reviewer = User::factory()->create();
    ConferenceReviewer::factory()->for($conference)->create(['user_id' => $reviewer->getKey()]);

    $submission = Submission::factory()->for($conference)->submitted()->create();
    SubmissionAuthor::factory()->for($submission)->create();
    SubmissionFile::factory()->for($submission)->create(['path' => 'ab/'.strtolower((string) str()->ulid()).'.pdf']);
    ReviewAssignment::factory()->create([
        'submission_id' => $submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
    ]);
    SubmissionDecision::factory()->for($submission)->create(['decision' => Decision::AcceptedOral]);

    $review = Review::factory()->create([
        'submission_id' => $submission->getKey(),
        'reviewer_user_id' => $reviewer->getKey(),
        'review_form_id' => $form->getKey(),
        'status' => ReviewStatus::Submitted,
        'submitted_at' => now(),
    ]);
    (new ReviewAnswer)->forceFill([
        'review_id' => $review->getKey(),
        'review_question_id' => $question->getKey(),
        'value_int' => 4, 'value_text' => null, 'value_bool' => null, 'choice_key' => null,
    ])->save();

    $form->forceFill(['locked_at' => now()])->save();

    // A soft-deleted abstract. A purge that only walks the visible ones leaves
    // rows behind and the RESTRICT key then refuses the conference itself.
    Submission::factory()->for($conference)->submitted()->create()->delete();

    return $conference->refresh();
}

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->platformAdmin()->create();
});

it('removes every row of one conference and touches nothing of the next', function () {
    $organization = Organization::factory()->approved()->create();
    $doomed = conferenceWithEverything($organization);
    $survivor = conferenceWithEverything($organization);

    $counts = app(PurgeConference::class)->handle($doomed, $this->admin);

    expect(Conference::withTrashed()->whereKey($doomed->getKey())->exists())->toBeFalse();

    foreach (['tracks', 'custom_fields', 'review_forms', 'email_templates', 'reviewer_invitations', 'conference_reviewers', 'reviewer_reminders'] as $table) {
        expect(DB::table($table)->where('conference_id', $doomed->getKey())->count())->toBe(0, $table);
    }

    expect(Submission::withTrashed()->where('conference_id', $doomed->getKey())->count())->toBe(0)
        // Exactly one of each is left: the survivor's.
        ->and(DB::table('review_questions')->count())->toBe(1)
        ->and(DB::table('reviews')->count())->toBe(1)
        ->and(DB::table('review_answers')->count())->toBe(1)
        ->and(DB::table('submission_decisions')->count())->toBe(1)
        ->and(DB::table('submission_authors')->count())->toBe(1)
        ->and(DB::table('submission_files')->count())->toBe(1)
        ->and(DB::table('review_assignments')->count())->toBe(1)
        // No foreign key reaches short_links, so this assertion is the only
        // thing that would notice them being forgotten.
        ->and(ShortLink::query()->where('target_id', $doomed->getKey())->count())->toBe(0)
        ->and(ShortLink::query()->count())->toBe(1);

    // And the organization itself is untouched: this is the whole point of a
    // conference-scoped variant.
    expect(Organization::whereKey($organization->getKey())->exists())->toBeTrue()
        ->and(Conference::whereKey($survivor->getKey())->exists())->toBeTrue()
        ->and($survivor->submissions()->count())->toBe(1);

    expect($counts['submissions'])->toBe(2)
        ->and($counts['reviews'])->toBe(1)
        ->and($counts['review_answers'])->toBe(1)
        ->and($counts['conferences'])->toBe(1)
        ->and($counts['private files'])->toBe(1);
});

it('deletes the file objects from the private disk, after the rows', function () {
    $conference = conferenceWithEverything();

    $path = (string) SubmissionFile::query()
        ->whereIn('submission_id', $conference->submissions()->withTrashed()->pluck('id'))
        ->value('path');

    Storage::disk('local')->put($path, 'pdf bytes');
    Storage::disk('local')->assertExists($path);

    app(PurgeConference::class)->handle($conference, $this->admin);

    // Otherwise cass-storage keeps objects nothing references, for ever.
    Storage::disk('local')->assertMissing($path);
});

it('survives a locked review form', function () {
    $conference = conferenceWithEverything();

    expect($conference->reviewForm()->first()?->isLocked())->toBeTrue();

    app(PurgeConference::class)->handle($conference, $this->admin);

    expect(DB::table('review_questions')->count())->toBe(0);
});

it('removes the email log rows of this conference and leaves the tenant-level ones', function () {
    $conference = conferenceWithEverything();
    $submission = $conference->submissions()->firstOrFail();

    // The factory, not `new EmailLog` + forceFill: email_logs.mailable and
    // email_logs.subject are NOT NULL with no default
    // (2026_09_11_001500_create_email_logs_table.php:29-31) and EmailLog::booted()
    // fills only `ulid`, so a hand-built row is a NOT NULL violation on both
    // SQLite and MySQL before the purge is ever called. Factory::make() wraps
    // creation in Model::unguarded(), so `$guarded = ['*']` is no obstacle.
    $scoped = EmailLog::factory()->create([
        'organization_id' => $conference->organization_id,
        'conference_id' => $conference->getKey(),
        'submission_id' => $submission->getKey(),
        'to_email' => 'author@example.org',
    ]);

    $tenantOnly = EmailLog::factory()->create([
        'organization_id' => $conference->organization_id,
        'to_email' => 'owner@example.org',
    ]);

    app(PurgeConference::class)->handle($conference, $this->admin);

    // All three keys are nullOnDelete, so "keep" would mean "keep and
    // anonymise", which is the worst of both. A row that is only the
    // organization's survives until the organization is purged.
    expect(EmailLog::query()->whereKey($scoped->getKey())->exists())->toBeFalse()
        ->and(EmailLog::query()->whereKey($tenantOnly->getKey())->exists())->toBeTrue();
});

it('leaves the activity log alone and adds one entry of its own', function () {
    $conference = conferenceWithEverything();
    activity()->performedOn($conference)->log('conference.published');

    app(PurgeConference::class)->handle($conference, $this->admin);

    // Decided: a purge does not erase the audit trail. The rows dangle -
    // activity_log's morphs are nullable and carry no foreign key - and an
    // erasure request is a person-shaped problem with a documented manual
    // query in the runbook, not a tenant-shaped one.
    expect(Activity::query()->where('description', 'conference.published')->exists())->toBeTrue();

    $purge = Activity::query()->where('description', 'conference.purged')->latest('id')->first();

    expect($purge)->not->toBeNull()
        // Performed on the ORGANIZATION: the conference no longer exists.
        ->and($purge?->subject_type)->toBe(Organization::class)
        ->and($purge?->causer_id)->toBe($this->admin->getKey())
        // getProperty(), not the getExtraProperty() of activitylog 3.x: 5.1.1
        // dropped the alias and Activity::getProperty() is the one reader of
        // the properties JSON (vendor/spatie/laravel-activitylog/src/Models/
        // Activity.php:66-69).
        ->and($purge?->getProperty('conference_ulid'))->toBe($conference->ulid);
});

it('previews exactly what it would delete, and deletes nothing', function () {
    $conference = conferenceWithEverything();

    $preview = app(PurgeConference::class)->preview($conference);

    expect(Conference::whereKey($conference->getKey())->exists())->toBeTrue();

    $counts = app(PurgeConference::class)->handle($conference, $this->admin);

    // Every table the real run reported has to be in the preview with the same
    // number, or the confirmation is a guess with a table in it.
    foreach ($counts as $table => $count) {
        expect($preview[$table] ?? null)->toBe($count, $table);
    }
});

it('finds nothing on a second call', function () {
    $conference = conferenceWithEverything();

    app(PurgeConference::class)->handle($conference, $this->admin);

    // An operator retrying a half-finished purge is exactly the case this has
    // to survive rather than fatal on.
    expect(array_sum(app(PurgeConference::class)->handle($conference, $this->admin)))->toBe(0);
});

it('leaves the tenant purge answering the same numbers as before', function () {
    // The refactor's own guard, beside DemoResetTest: PurgeOrganization now
    // delegates the conference subtree to PurgeConference, and this asserts the
    // two halves still add up to one whole tenant.
    $organization = Organization::factory()->approved()->create();
    conferenceWithEverything($organization);
    conferenceWithEverything($organization);

    $counts = app(PurgeOrganization::class)->handle($organization, $this->admin);

    expect(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse()
        ->and($counts['conferences'])->toBe(2)
        ->and($counts['submissions'])->toBe(4)
        ->and($counts['reviews'])->toBe(2)
        ->and($counts['private files'])->toBe(2)
        ->and($counts['organizations'])->toBe(1);
});

it('sweeps the legacy mapping rows with the conference, and keeps the user mappings', function () {
    $conference = conferenceWithEverything();
    LegacyImport::record('conferences', 5, $conference);
    LegacyImport::record('submissions', 5, $conference->submissions()->firstOrFail());
    LegacyImport::record('review_forms', 5, $conference->reviewForms()->firstOrFail());
    $userMapping = LegacyImport::record('users', 5, $this->admin);

    app(PurgeConference::class)->handle($conference, $this->admin);

    // legacy_imports carries no foreign key - polymorphic, like short_links -
    // so nothing removes these for you, and a re-import would then find a
    // mapping to a row that no longer exists and skip a conference it should
    // have created.
    //
    // `users` is the exception: no purge in this application deletes a User
    // row, so that mapping is still TRUE after the purge and sweeping it would
    // only make a re-import re-adopt the same account by address.
    expect(LegacyImport::query()->where('legacy_table', '!=', 'users')->count())->toBe(0)
        ->and(LegacyImport::query()->whereKey($userMapping->getKey())->exists())->toBeTrue();
});
