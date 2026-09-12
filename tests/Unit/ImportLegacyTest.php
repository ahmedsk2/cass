<?php

declare(strict_types=1);

use App\Actions\Legacy\ImportLegacy;
use App\Enums\ConferenceStatus;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\ReviewQuestionType;
use App\Enums\ReviewStatus;
use App\Enums\SubmissionStatus;
use App\Exceptions\LegacyImportRefused;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\LegacyImport;
use App\Models\Organization;
use App\Models\ReviewAnswer;
use App\Models\ReviewerInvitation;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->dump = base_path('tests/Fixtures/legacy/dump.sql');
    $this->uploads = sys_get_temp_dir().'/cass-legacy-uploads';

    // The fixture's own INVENTED invitation tokens, copied verbatim from the
    // two `reviewer_invitations` rows in tests/Fixtures/legacy/dump.sql (legacy
    // ids 17 and 24, in that order) so the "never writes a legacy plaintext
    // token into the report" case asserts against strings the import actually
    // reads. If you edit those two rows, edit these two values with them.
    // Nothing from the real dump is ever typed into this repo.
    $this->fixtureInvitationTokens = [
        '4dfca4e3f1cedae5bd705c3302c50877',
        '6fcf89e9c05b44a51d7d5239170e454c',
    ];

    if (! is_dir($this->uploads)) {
        mkdir($this->uploads, 0777, true);
    }

    // Two of the three referenced files exist; the third does not, which is
    // the "a row points at a file that is not on disk" case Task 10 asserts.
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1699719268_4752.pdf');
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1699719578_3346.pdf');
    // And one file on disk that no row references (the real dump has three).
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1727756783_9881.pdf');

    // The organization is the owner's to create, in the panel, before the
    // import runs. The command adopts it; it does not invent a name, a type or
    // a country.
    $this->organization = Organization::factory()->approved()->create([
        'name' => 'Example Society',
        'slug' => 'example-society',
    ]);
});

it('refuses when the organization slug names nothing', function () {
    expect(fn () => app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'no-such-society'))
        ->toThrow(LegacyImportRefused::class, __('legacy.errors.no_organization', ['slug' => 'no-such-society']));
});

it('refuses when the uploads directory is not there', function () {
    expect(fn () => app(ImportLegacy::class)->handle($this->dump, '/no/such/uploads', 'example-society'))
        ->toThrow(LegacyImportRefused::class);
});

it('creates one conference per legacy edition, archived, with a year in the slug', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conferences = Conference::query()->orderBy('slug')->get();

    expect($conferences)->toHaveCount(2)
        // The two legacy names are byte-identical, so the year - derived from
        // submission_deadline, the only column that carries one - is what makes
        // the slugs distinct.
        ->and($conferences[0]->slug)->toBe('example-pediatric-symposium-2023')
        ->and($conferences[1]->slug)->toBe('example-pediatric-symposium-2024')
        ->and($conferences[0]->status)->toBe(ConferenceStatus::Archived)
        // legacy-review.md:239: "there is no average, weighting, ranking, or
        // accept/reject decision anywhere in the code" - so there is nothing
        // to have decided, and `decided` would be a claim the data cannot make.
        ->and($conferences[0]->status)->not->toBe(ConferenceStatus::Decided)
        ->and($conferences[0]->review_mode)->toBe(ReviewMode::OpenPool)
        // Every legacy row has exactly one attachment.
        ->and($conferences[0]->max_files)->toBe(1)
        ->and($conferences[0]->allowed_file_types)->toBe(['pdf'])
        ->and($conferences[0]->submission_deadline?->toDateString())->toBe('2023-12-02');
});

it('creates users without a usable password and without email verification', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $reviewer = User::query()->where('email', 'reviewer.one@example.org')->first();

    expect($reviewer)->not->toBeNull()
        ->and($reviewer?->name)->toBe('Dr Reviewer One')
        // Spec 5.10: "users (without passwords; they receive a reset link on
        // first login)". The legacy hashes are real bcrypt and WOULD work,
        // which is exactly why they are not carried: legacy-review.md:276
        // records that users.reset_token doubled as the invitation token, so a
        // password there was never the only way in.
        ->and(Hash::check('anything', (string) $reviewer?->password))->toBeFalse()
        ->and($reviewer?->email_verified_at)->toBeNull()
        ->and($reviewer?->is_platform_admin)->toBeFalse();

    // Addresses are folded: users.email is UNIQUE in legacy but stored with
    // mixed case, and v2 compares against a lower-cased column.
    expect(User::query()->where('email', 'manager.one@example.org')->exists())->toBeTrue();
});

it('makes the legacy admin and managers organization members, and says so in the report', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $manager = User::query()->where('email', 'manager.one@example.org')->firstOrFail();
    $owner = User::query()->where('email', 'owner@example.org')->firstOrFail();

    // Legacy conference_managers scopes a manager to ONE edition; v2 scopes an
    // organizer to an organization (spec section 3). That widening is real and
    // is reported rather than hidden.
    expect($manager->roleIn($this->organization))->not->toBeNull()
        ->and($owner->roleIn($this->organization))->not->toBeNull();

    $widened = array_values(array_filter(
        $report->manualReview(),
        fn (string $line): bool => str_contains($line, 'manager.one@example.org') && str_contains($line, 'conference_managers'),
    ));

    expect($widened)->not->toBeEmpty();
});

it('copies each legacy form and its questions, with a minimum of 1 on every scale', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2023')->firstOrFail();
    $form = $conference->reviewForm()->firstOrFail();

    expect(ReviewForm::query()->count())->toBe(2)
        ->and($form->is_active)->toBeTrue()
        ->and($form->questions()->count())->toBe(2);

    $question = $form->questions()->orderBy('sort')->first();

    expect($question?->scale_max)->toBe(5)
        // THE line that decides whether the import scores anything at all.
        // Legacy has likert_scale and no minimum; AnswerNormaliser::likert()
        // returns null when min is null, so leaving it would silently make
        // every imported answer unscored.
        ->and($question?->scale_min)->toBe(1)
        ->and($question?->type)->toBe(ReviewQuestionType::Likert);

    // Form 6's order_column is 0 on every row (the legacy ordering bug), so
    // sort is synthesised from the id sequence rather than copied.
    expect($form->questions()->orderBy('sort')->pluck('sort')->all())->toBe([1, 2]);
});

it('maps a textarea question to a text question', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2024')->firstOrFail();
    $text = $conference->reviewForm()->firstOrFail()->questions()->where('type', ReviewQuestionType::Text)->first();

    expect($text)->not->toBeNull()
        ->and($text?->scale_min)->toBeNull()
        ->and($text?->scale_max)->toBeNull();
});

it('attaches reviewers to their conference and skips the junk row', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2023')->firstOrFail();

    expect($conference->reviewers()->count())->toBe(2)
        ->and($conference->activeReviewers()->count())->toBe(2)
        ->and($conference->reviewers()->first()?->status)->toBe(ReviewerStatus::Active)
        // legacy-review.md:196: "any request to this page without a token
        // inserts a junk row" - (NULL, NULL) in conference_reviewers. It is
        // not in this dump, but it is in the schema, and a mapper that
        // dereferences it fatals.
        ->and(ConferenceReviewer::query()->whereNull('conference_id')->count())->toBe(0);
});

it('imports invitations as expired, never as usable', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $invitation = ReviewerInvitation::query()->where('email', 'never.accepted@example.org')->first();

    expect($invitation)->not->toBeNull()
        // v2 stores char('token_hash', 64); legacy holds a 32-character
        // plaintext MD5, three of which are still live in the real dump. They
        // cannot be carried, and re-hashing one would MINT a working
        // invitation out of a dead one. The value compared against is the
        // FIXTURE's invented token for THIS row - legacy `reviewer_invitations`
        // id 24, which is index 1 of the pair recorded in beforeEach - so the
        // assertion is about the row under test and not about some other row's
        // string. No real legacy value is ever typed into this repository.
        ->and($invitation?->token_hash)->not->toBe($this->fixtureInvitationTokens[1])
        ->and($invitation?->expires_at?->isPast())->toBeTrue()
        ->and($invitation?->accepted_at)->toBeNull();

    // 'Pending' with a capital P against a lowercase enum
    // (legacy-review.md:269) must not become a fourth state.
    expect(ReviewerInvitation::query()->count())->toBe(2);
});

it('never writes a legacy plaintext token into the manual-review report', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $markdown = (string) Storage::disk('local')->get((string) $report->reportPath);

    // The fixture's own tokens, plus the shape of every legacy one (md5 hex).
    // The report lands on the cass-storage volume and the runbook tells an
    // operator to `cat` it, so a token value written there outlives the dump
    // itself.
    foreach ($this->fixtureInvitationTokens as $token) {
        expect($markdown)->not->toContain($token);
    }

    expect($markdown)->not->toMatch('/\b[0-9a-f]{32}\b/')
        ->and($markdown)->toContain('reviewer_invitations#');
});

it('is idempotent: a second run creates nothing and changes nothing', function () {
    $first = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');
    $counts = [
        'users' => User::query()->count(),
        'conferences' => Conference::query()->count(),
        'forms' => ReviewForm::query()->count(),
        'questions' => ReviewQuestion::query()->count(),
        'reviewers' => ConferenceReviewer::query()->count(),
        'mappings' => LegacyImport::query()->count(),
    ];

    $second = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    // Spec 5.10: "Idempotent by legacy id." The unique index on
    // (legacy_table, legacy_id) is the guarantee; the control flow is the
    // convenience.
    expect(User::query()->count())->toBe($counts['users'])
        ->and(Conference::query()->count())->toBe($counts['conferences'])
        ->and(ReviewForm::query()->count())->toBe($counts['forms'])
        ->and(ReviewQuestion::query()->count())->toBe($counts['questions'])
        ->and(ConferenceReviewer::query()->count())->toBe($counts['reviewers'])
        ->and(LegacyImport::query()->count())->toBe($counts['mappings'])
        ->and($second->created['conferences'] ?? 0)->toBe(0)
        ->and($first->created['conferences'] ?? 0)->toBe(2);
});

it('writes nothing at all in dry-run mode, and still reports what it would have done', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society', dryRun: true);

    expect(Conference::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(LegacyImport::query()->count())->toBe(0)
        ->and($report->created['conferences'] ?? 0)->toBe(2)
        ->and($report->manualReview())->not->toBeEmpty();
});

it('imports every abstract with a reference, a token and its conference counter raised', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $conference = Conference::query()->where('slug', 'example-pediatric-symposium-2023')->firstOrFail();

    expect($conference->submissions()->count())->toBe(2)
        // Minted in submission_date order by Plan 3's AllocateReference, and
        // the counter left where the last one ended - or a future submission
        // to this conference collides.
        ->and($conference->submissions()->orderBy('id')->pluck('reference')->all())
        ->toBe([$conference->reference_prefix.'-001', $conference->reference_prefix.'-002'])
        ->and($conference->fresh()?->submission_counter)->toBe(2);

    $submission = $conference->submissions()->orderBy('id')->first();

    expect($submission?->access_token_hash)->toHaveLength(64)
        ->and($submission?->status)->toBe(SubmissionStatus::UnderReview)
        ->and($submission?->word_count)->toBeGreaterThan(0)
        // The browser's clock, which is all legacy has (legacy-review.md:280).
        ->and($submission?->submitted_at?->toDateString())->toBe('2023-11-11');
});

it('parses the authors out of the text columns', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $bulleted = Submission::query()->where('title', 'like', 'A child%')->firstOrFail();

    expect($bulleted->authors()->count())->toBe(3)
        ->and($bulleted->authors()->orderBy('sort')->pluck('name')->all())
        ->toBe(['Dr Alia Example', 'Dr Badr Example', 'Dr Carim Example'])
        ->and($bulleted->authors()->where('is_corresponding', true)->count())->toBe(1);
});

it('gives every author without an address a non-routable one, and reports each', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $invented = SubmissionAuthor::query()->where('email', 'like', '%@import.invalid')->get();

    // submission_authors.email is NOT NULL and legacy stores no author email
    // at all. .invalid is RFC 2606's reserved TLD: it cannot resolve, so
    // nothing can ever be delivered to one of these by accident.
    expect($invented)->not->toBeEmpty()
        ->and($invented->first()?->email)->toMatch('/^legacy-\d+-\d+@import\.invalid$/')
        ->and($invented->first()?->is_corresponding)->toBeFalse()
        ->and(implode("\n", $report->manualReview()))->toContain('import.invalid');
});

it('leaves the 2023 affiliation column out when it holds a person, and reports it', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $swapped = Submission::query()->where('title', 'like', 'Bedside ultrasound%')->firstOrFail();
    $clean = Submission::query()->where('title', 'like', 'Sepsis recognition%')->firstOrFail();

    // Conference 5's affiliation column holds a name that is also in the
    // byline, so it is not an affiliation and is not written as one.
    expect($swapped->authors()->pluck('affiliation')->filter()->all())->toBe([])
        // Conference 6 is clean and the value is used.
        ->and($clean->authors()->first()?->affiliation)->toBe('Example Central Hospital');

    expect(implode("\n", $report->manualReview()))->toContain('affiliation');
});

it('copies each file with its digest and reports the ones that are missing and the ones that are orphans', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $files = SubmissionFile::query()->get();

    // Two of the three referenced files exist on disk in the fixture.
    expect($files)->toHaveCount(2)
        ->and($files->first()?->sha256)->toHaveLength(64)
        ->and($files->first()?->mime)->toBe('application/pdf')
        ->and($files->first()?->size)->toBeGreaterThan(0)
        // The path is the same content-addressed shape StoreSubmissionFile
        // writes, so the download route and the purge both work unchanged.
        ->and($files->first()?->path)->toMatch('#^[0-9a-f]{2}/[0-9A-Za-z]{26}\.pdf$#');

    Storage::disk('local')->assertExists((string) $files->first()?->path);

    $lines = implode("\n", $report->manualReview());

    expect($lines)->toContain('1729330644_2837.pdf')   // referenced, not on disk
        ->and($lines)->toContain('1727756783_9881.pdf'); // on disk, referenced by nothing
});

it('builds one review per reviewer per abstract, with the answers typed', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $submission = Submission::query()->where('title', 'like', 'Bedside ultrasound%')->firstOrFail();

    // Legacy has no review header row at all: a "review" is the set of answer
    // rows sharing (submission_id, reviewer_id).
    expect($submission->reviews()->count())->toBe(2)
        ->and($submission->reviews()->first()?->status)->toBe(ReviewStatus::Submitted)
        ->and(ReviewAnswer::query()->count())->toBe(5)
        // Every legacy answer is a digit 1-5 in a text column.
        ->and(ReviewAnswer::query()->whereNotNull('value_int')->count())->toBe(5)
        ->and(ReviewAnswer::query()->whereNotNull('value_text')->count())->toBe(0);

    // Synthetic and reported: there is no legacy timestamp, and a review must
    // not be dated before the abstract it reviews.
    expect($submission->reviews()->first()?->submitted_at?->greaterThan($submission->submitted_at))->toBeTrue();
});

it('imports an incomplete review as submitted, and reports it', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $submission = Submission::query()->where('title', 'like', 'Sepsis recognition%')->firstOrFail();
    $review = $submission->reviews()->first();

    // One answer of the two questions on form 7 - the shape of the two real
    // incomplete 2024 reviews. Marking it draft would drop it out of
    // review_count, out of the mean, and out of the record of what the
    // committee was actually given.
    expect($review?->status)->toBe(ReviewStatus::Submitted)
        ->and($review?->answers()->count())->toBe(1)
        ->and(implode("\n", $report->manualReview()))->toContain('incomplete');
});

it('scores every imported abstract and locks the forms afterwards', function () {
    app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    $submission = Submission::query()->where('title', 'like', 'Bedside ultrasound%')->firstOrFail();

    // Recomputed by ComputeSubmissionScore rather than carried: legacy's
    // "Total Score" was an un-normalised SUM across reviewers and questions
    // (legacy-review.md:267) and is not comparable to anything v2 computes.
    // Reviewer 16 gave 4 and 5 -> 87.50; reviewer 17 gave 3 and 2 -> 37.50.
    expect($submission->score)->toBe('62.50')
        ->and($submission->review_count)->toBe(2)
        ->and($submission->score_spread)->not->toBeNull();

    // Locked LAST: ReviewQuestion::booted()'s updating hook throws
    // ReviewFormLocked on a locked form, so a form locked before its questions
    // are final cannot be corrected.
    expect(ReviewForm::query()->whereNull('locked_at')->count())->toBe(0);
});

it('writes the manual-review report to the private disk and names it in the result', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society');

    expect($report->reportPath)->toStartWith('legacy/')
        ->and($report->reportPath)->toEndWith('.md');

    Storage::disk('local')->assertExists($report->reportPath);

    $markdown = (string) Storage::disk('local')->get($report->reportPath);

    // Grouped and counted, because a flat list of ninety lines is a file
    // nobody finishes reading.
    expect($markdown)->toContain('# Legacy import')
        ->and($markdown)->toContain('## submissions')
        ->and($markdown)->toContain('import.invalid');
});

it('writes no report file and no rows in dry-run mode', function () {
    $report = app(ImportLegacy::class)->handle($this->dump, $this->uploads, 'example-society', dryRun: true);

    expect(Submission::query()->count())->toBe(0)
        ->and($report->manualReview())->not->toBeEmpty()
        // The report is the POINT of a dry run, so it is returned - it is just
        // not written to a disk the operator would then have to clean up.
        ->and($report->reportPath)->toBeNull();
});
