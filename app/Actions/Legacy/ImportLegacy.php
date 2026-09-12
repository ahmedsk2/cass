<?php

declare(strict_types=1);

namespace App\Actions\Legacy;

use App\Actions\Submissions\AllocateReference;
use App\Actions\Submissions\ComputeSubmissionScore;
use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
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
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewerInvitation;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Files\SniffedMimeType;
use App\Support\Legacy\AuthorList;
use App\Support\Legacy\ImportReport;
use App\Support\Legacy\SqlDumpReader;
use App\Support\Submissions\ReferencePrefix;
use App\Support\Text\WordCounter;
use App\Support\Tokens\InvitationToken;
use App\Support\Tokens\SubmissionToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The legacy CASS database, mapped into this one (spec 5.10).
 *
 * Everything, in one order: the organization's membership, the two
 * conferences, the users, the review forms and their questions, the reviewer
 * memberships and the invitations - and then the abstracts, their authors
 * parsed out of three overlapping text columns, their files copied into
 * private storage, and their reviews with the answers typed and the scores
 * recomputed.
 *
 * Three rules run through all of it:
 *
 *   1. **Idempotent by legacy id.** Every row written is recorded in
 *      `legacy_imports`, and every step begins by asking that table whether it
 *      has been here before. The unique index on (legacy_table, legacy_id) is
 *      the guarantee; the control flow is the convenience.
 *   2. **It refuses to guess, and everything it refuses goes into a report.**
 *      Spec section 15: *"Import command normalises encoding and reports rows
 *      needing manual review instead of guessing."*
 *   3. **Nothing legacy is carried that could be used to get in.** No password
 *      hash, no invitation token. Both would work, and neither should.
 */
final class ImportLegacy
{
    public function __construct(
        private readonly AllocateReference $references,
        private readonly ComputeSubmissionScore $scores,
    ) {}

    /**
     * @param  string  $dumpPath  The phpMyAdmin mysqldump to read.
     * @param  string  $uploadsPath  The flat directory of legacy PDFs.
     * @param  string  $organizationSlug  An organization that already exists.
     * @param  bool  $dryRun  Run everything, then roll it back.
     *
     * @throws LegacyImportRefused
     */
    public function handle(string $dumpPath, string $uploadsPath, string $organizationSlug, bool $dryRun = false): ImportReport
    {
        $organization = Organization::query()->where('slug', $organizationSlug)->first();

        if (! $organization instanceof Organization) {
            throw LegacyImportRefused::because(__('legacy.errors.no_organization', ['slug' => $organizationSlug]));
        }

        if (! $organization->isApproved()) {
            throw LegacyImportRefused::because(__('legacy.errors.not_approved', ['slug' => $organizationSlug]));
        }

        if (! is_file($dumpPath) || ! is_readable($dumpPath)) {
            throw LegacyImportRefused::because(__('legacy.errors.unreadable_dump', ['path' => $dumpPath]));
        }

        // Checked here even though only the second half reads it: an operator
        // who mistyped the path should find out before twenty-four abstracts
        // have been written without their files.
        if (! is_dir($uploadsPath)) {
            throw LegacyImportRefused::because(__('legacy.errors.no_uploads', ['path' => $uploadsPath]));
        }

        $reader = new SqlDumpReader($dumpPath);
        $report = new ImportReport;

        // begin/commit by hand rather than DB::transaction(): a dry run is the
        // real run rolled back, and calling DB::rollBack() from inside a
        // DB::transaction() callback leaves the manager's own level counter out
        // of step with the connection - the callback returns, the manager
        // commits a transaction that is no longer open, and the next write in
        // the same process runs outside a transaction nobody noticed had gone.
        // A dry run that took a different path through this code would prove
        // nothing anyway; this one exercises every constraint, cast and unique
        // index and then throws the rows away.
        DB::beginTransaction();

        try {
            $this->run($reader, $organization, $uploadsPath, $dryRun, $report);

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        // Read as UTF-8 and never transcoded, so a value the reader could not
        // read is a line in the report rather than a silent conversion.
        foreach ($reader->encodingProblems() as $index => $excerpt) {
            $report->manual->add('dump', $index + 1, __('legacy.review.encoding', ['excerpt' => $excerpt]));
        }

        // Outside the transaction, and NOT written for a dry run: a rehearsal
        // that leaves a file behind is a file the operator then has to tell
        // apart from the real one on the same volume. The command prints the
        // list instead, which is the whole reason the list is on the report
        // object rather than only in the file.
        $report->reportPath = $dryRun ? null : $this->writeReport($report, $organization);

        return $report;
    }

    private function run(SqlDumpReader $reader, Organization $organization, string $uploadsPath, bool $dryRun, ImportReport $report): void
    {
        $users = $this->importUsers($reader, $report);
        $this->importMembers($reader, $organization, $users, $report);
        $conferences = $this->importConferences($reader, $organization, $report);
        $this->importReviewForms($reader, $conferences, $report);
        $this->importReviewers($reader, $conferences, $users, $report);
        $this->importInvitations($reader, $conferences, $users, $report);
        $submissions = $this->importSubmissions($reader, $conferences, $uploadsPath, $dryRun, $report);
        $this->importReviews($reader, $submissions, $users, $report);
        $this->lockAndScore($conferences);
    }

    /**
     * Legacy `users`, keyed by legacy id.
     *
     * No password is carried, even though all ten legacy rows hold real bcrypt
     * hashes this application would accept. Spec 5.10 says "users (without
     * passwords; they receive a reset link on first login)", and the reason is
     * better than the instruction: the legacy `users.reset_token` column
     * doubled as the manager-invitation token, so a password there was never
     * the only way into an account. Each imported user gets a value nobody has
     * ever seen and no `email_verified_at`.
     *
     * @return array<int, User>
     */
    private function importUsers(SqlDumpReader $reader, ImportReport $report): array
    {
        $users = [];

        foreach ($reader->rows('users') as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $email = $this->email($row['email'] ?? null);

            if ($legacyId === 0 || $email === '') {
                continue;
            }

            $mapped = LegacyImport::find('users', $legacyId);

            if ($mapped instanceof User) {
                $users[$legacyId] = $mapped;
                $report->recordSkipped('users');

                continue;
            }

            // Adopt rather than duplicate: the owner may already have an
            // account on this platform under the same address, and a second
            // row would be a second login for the same person.
            $user = User::query()->where('email', $email)->first();

            if ($user instanceof User) {
                $report->recordSkipped('users');
            } else {
                $user = new User;
                $user->forceFill([
                    'name' => $this->personName($row['full_name'] ?? null, $email),
                    'email' => $email,
                    // Hashed by the `password` cast on the way in.
                    'password' => Str::random(64),
                    'email_verified_at' => null,
                ])->save();

                $report->recordCreated('users');
            }

            LegacyImport::record('users', $legacyId, $user);
            $users[$legacyId] = $user;

            // A legacy `admin` is an organization OWNER here, never
            // is_platform_admin: the legacy admin ran one society's two
            // symposia, not a platform.
            $role = strtolower(trim((string) ($row['role'] ?? '')));

            if (! in_array($role, ['admin', 'manager', 'reviewer'], true)) {
                $report->manual->add('users', $legacyId, __('legacy.review.unknown_role', [
                    'role' => $role,
                    'email' => $email,
                ]));
            }
        }

        return $users;
    }

    /**
     * The legacy admin becomes the organization's owner and every legacy
     * conference manager becomes an admin of it.
     *
     * Legacy `conference_managers` scopes a manager to one edition; v2 scopes
     * an organizer to an organization (spec section 3). There is no
     * per-conference manager model to map onto, so the widening is real - and
     * it is reported for every manager rather than quietly applied.
     *
     * @param  array<int, User>  $users
     */
    private function importMembers(SqlDumpReader $reader, Organization $organization, array $users, ImportReport $report): void
    {
        foreach ($reader->rows('users') as $row) {
            if (strtolower(trim((string) ($row['role'] ?? ''))) !== 'admin') {
                continue;
            }

            $user = $users[(int) ($row['id'] ?? 0)] ?? null;

            if ($user instanceof User) {
                $this->addMember($organization, $user, OrganizationRole::Owner, $report);
            }
        }

        /** @var array<int, list<string>> $managed */
        $managed = [];

        foreach ($reader->rows('conference_managers') as $row) {
            if ($row['manager_id'] === null) {
                continue;
            }

            $managed[(int) $row['manager_id']][] = $row['conference_id'] === null ? '?' : (string) $row['conference_id'];
        }

        foreach ($managed as $legacyManagerId => $editions) {
            $user = $users[$legacyManagerId] ?? null;

            if (! $user instanceof User) {
                $report->manual->add('conference_managers', $legacyManagerId, __('legacy.review.missing_manager'));

                continue;
            }

            $this->addMember($organization, $user, OrganizationRole::Admin, $report);

            $report->manual->add('conference_managers', $legacyManagerId, __('legacy.review.manager_widened', [
                'email' => (string) $user->email,
                'editions' => implode(', ', $editions),
                'organization' => (string) $organization->name,
            ]));
        }
    }

    private function addMember(Organization $organization, User $user, OrganizationRole $role, ImportReport $report): void
    {
        // Never a downgrade: the legacy admin may also appear in
        // conference_managers, and an owner must not become an admin because a
        // later row said so.
        if ($user->roleIn($organization) !== null) {
            $report->recordSkipped('organization members');

            return;
        }

        $organization->addMember($user, $role);
        $report->recordCreated('organization members');
    }

    /**
     * Legacy `conferences`, keyed by legacy id.
     *
     * The table has three columns - id, name, submission_deadline - and the two
     * editions share a byte-identical name, so the deadline's year is the only
     * thing that can make two slugs. Everything else is either a schema default
     * or one of the six choices this import makes on purpose, and `archived` is
     * one of them: there is no accept/reject decision anywhere in the legacy
     * code, so `decided` would be a claim the data cannot make.
     *
     * @return array<int, Conference>
     */
    private function importConferences(SqlDumpReader $reader, Organization $organization, ImportReport $report): array
    {
        $conferences = [];

        foreach ($reader->rows('conferences') as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $mapped = LegacyImport::find('conferences', $legacyId);

            if ($mapped instanceof Conference) {
                $conferences[$legacyId] = $mapped;
                $report->recordSkipped('conferences');

                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $deadline = $this->dateTime($row['submission_deadline'] ?? null);

            $conference = new Conference([
                'name' => $name,
                'timezone' => 'Asia/Riyadh',
                'submission_deadline' => $deadline,
                // Legacy had no per-submission assignment at all: every
                // reviewer of a conference could review every abstract.
                'review_mode' => ReviewMode::OpenPool,
                'blind_review' => false,
                // The legacy submit form checked 500 words client-side.
                'word_limit' => 500,
                // Every legacy row has exactly one attachment.
                'max_files' => 1,
                'allowed_file_types' => ['pdf'],
            ]);

            $conference->organization()->associate($organization);
            $conference->forceFill([
                'slug' => $this->conferenceSlug($organization, $name, $deadline),
                'status' => ConferenceStatus::Archived,
                // Derived from the edition's own year rather than from today's,
                // so the prefix printed on an imported reference names the year
                // the abstract was actually written in.
                'reference_prefix' => ReferencePrefix::derive($name, $deadline),
            ])->save();

            $report->recordCreated('conferences');
            LegacyImport::record('conferences', $legacyId, $conference);
            $conferences[$legacyId] = $conference;
        }

        return $conferences;
    }

    private function conferenceSlug(Organization $organization, string $name, ?CarbonImmutable $deadline): string
    {
        $base = Str::slug($name) ?: 'conference';

        if ($deadline instanceof CarbonImmutable) {
            $base .= '-'.$deadline->format('Y');
        }

        $slug = $base;
        $n = 2;

        // Two editions in the same calendar year would otherwise collide on
        // conferences' unique (organization_id, slug).
        while (Conference::withTrashed()->where('organization_id', $organization->getKey())->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * One active review form per conference, with its questions.
     *
     * `scale_min` is written as **1** rather than left null, which is the line
     * that decides whether the import scores anything at all: legacy stores a
     * `likert_scale` and no minimum, and AnswerNormaliser::likert() returns
     * null when the minimum is null, so a null here would silently make every
     * imported answer unscored.
     *
     * `locked_at` is deliberately left null. ReviewQuestion's `updating` hook
     * throws on a locked form, so a form locked before its questions are final
     * cannot be corrected; the second half of the import locks it once the
     * answers are in.
     *
     * @param  array<int, Conference>  $conferences
     */
    private function importReviewForms(SqlDumpReader $reader, array $conferences, ImportReport $report): void
    {
        /** @var array<int, list<array<string, string|null>>> $questions */
        $questions = [];

        foreach ($reader->rows('evaluation_questions') as $row) {
            $questions[(int) ($row['evaluation_form_id'] ?? 0)][] = $row;
        }

        foreach ($reader->rows('evaluation_forms') as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $conference = $conferences[(int) ($row['conference_id'] ?? 0)] ?? null;

            if (! $conference instanceof Conference) {
                $report->manual->add('evaluation_forms', $legacyId, __('legacy.review.orphan_form'));
                $report->recordSkipped('review forms');

                continue;
            }

            $form = LegacyImport::find('evaluation_forms', $legacyId);

            if ($form instanceof ReviewForm) {
                $report->recordSkipped('review forms');
            } else {
                $form = new ReviewForm(['name' => 'Review form']);
                $form->conference()->associate($conference);
                // Eloquent never reads a column default back after an INSERT,
                // and is_active is not fillable, so without this the instance
                // the questions are hung off reports null.
                $form->is_active = true;
                $form->save();

                $report->recordCreated('review forms');
                LegacyImport::record('evaluation_forms', $legacyId, $form);
            }

            $this->importQuestions($questions[$legacyId] ?? [], $form, $legacyId, $this->dateTime($row['created_at'] ?? null), $report);
        }
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private function importQuestions(array $rows, ReviewForm $form, int $legacyFormId, ?CarbonImmutable $formCreatedAt, ImportReport $report): void
    {
        usort($rows, static fn (array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));

        $synthesiseOrder = $rows !== [];

        foreach ($rows as $row) {
            if ((int) ($row['order_column'] ?? 0) !== 0) {
                $synthesiseOrder = false;

                break;
            }
        }

        if ($synthesiseOrder) {
            $report->manual->add('evaluation_forms', $legacyFormId, __('legacy.review.question_order_synthesised'));
        }

        foreach ($rows as $index => $row) {
            $legacyId = (int) ($row['id'] ?? 0);

            if (LegacyImport::find('evaluation_questions', $legacyId) instanceof ReviewQuestion) {
                $report->recordSkipped('review questions');

                continue;
            }

            $isText = strtolower(trim((string) ($row['question_type'] ?? ''))) === 'textarea';
            $scaleMax = $row['likert_scale'] === null ? null : (int) $row['likert_scale'];

            $question = $form->questions()->create([
                'prompt' => trim((string) ($row['question'] ?? '')),
                'type' => $isText ? ReviewQuestionType::Text : ReviewQuestionType::Likert,
                'scale_min' => $isText ? null : 1,
                'scale_max' => $isText ? null : ($scaleMax ?? 5),
                'required' => true,
                'sort' => $synthesiseOrder ? $index + 1 : (int) ($row['order_column'] ?? 0),
            ]);

            $report->recordCreated('review questions');
            LegacyImport::record('evaluation_questions', $legacyId, $question);

            $createdAt = $this->dateTime($row['created_at'] ?? null);

            if ($formCreatedAt instanceof CarbonImmutable && $createdAt instanceof CarbonImmutable && $createdAt->lessThan($formCreatedAt)) {
                $report->manual->add('evaluation_questions', $legacyId, __('legacy.review.question_created_before_form'));
            }
        }
    }

    /**
     * Legacy `conference_reviewers`, which has no unique key and two nullable
     * columns: any request to the legacy accept page without a token inserted a
     * (NULL, NULL) row. Those are reported and skipped, because a mapper that
     * dereferences one fatals halfway through an import.
     *
     * @param  array<int, Conference>  $conferences
     * @param  array<int, User>  $users
     */
    private function importReviewers(SqlDumpReader $reader, array $conferences, array $users, ImportReport $report): void
    {
        /** @var array<int, true> $answered */
        $answered = [];

        foreach ($reader->rows('reviews') as $row) {
            if ($row['reviewer_id'] !== null) {
                $answered[(int) $row['reviewer_id']] = true;
            }
        }

        [$acceptedAt, $sentAt] = $this->invitationDates($reader);

        foreach ($reader->rows('conference_reviewers') as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $legacyReviewerId = $row['reviewer_id'] === null ? null : (int) $row['reviewer_id'];
            $conference = $row['conference_id'] === null ? null : ($conferences[(int) $row['conference_id']] ?? null);
            $user = $legacyReviewerId === null ? null : ($users[$legacyReviewerId] ?? null);

            if (! $conference instanceof Conference || ! $user instanceof User) {
                $report->manual->add('conference_reviewers', $legacyId, __('legacy.review.orphan_reviewer'));
                $report->recordSkipped('conference reviewers');

                continue;
            }

            if (LegacyImport::find('conference_reviewers', $legacyId) instanceof ConferenceReviewer) {
                $report->recordSkipped('conference reviewers');
            } else {
                $email = (string) $user->email;
                // The legacy invitation is the only record of when a reviewer
                // joined. With no invitation, the edition's own deadline is a
                // floor: they were certainly a reviewer by then.
                $accepted = $acceptedAt[$email] ?? $this->deadlineOf($conference);

                $reviewer = ConferenceReviewer::query()
                    ->where('conference_id', $conference->getKey())
                    ->where('user_id', $user->getKey())
                    ->first() ?? new ConferenceReviewer;

                $reviewer->forceFill([
                    'conference_id' => $conference->getKey(),
                    'user_id' => $user->getKey(),
                    'status' => ReviewerStatus::Active,
                    'invited_at' => $sentAt[$email] ?? $accepted,
                    'accepted_at' => $accepted,
                    'removed_at' => null,
                ])->save();

                $report->recordCreated('conference reviewers');
                LegacyImport::record('conference_reviewers', $legacyId, $reviewer);
            }

            // $legacyReviewerId is an int here: a null one made $user null and
            // the guard above already skipped the row.
            if (! isset($answered[$legacyReviewerId])) {
                $report->manual->add('conference_reviewers', $legacyId, __('legacy.review.reviewer_never_reviewed', [
                    'email' => (string) $user->email,
                    'conference' => (string) $conference->name,
                ]));
            }
        }
    }

    /**
     * Legacy `reviewer_invitations`, imported as a record that an invitation
     * happened and never as a way in.
     *
     * v2 stores a SHA-256 `token_hash`; legacy holds a 32-character plaintext
     * MD5, three of which are still live in the real dump. Carrying one is
     * impossible and re-hashing one would MINT a working invitation out of a
     * dead one, so every imported row gets a fresh hash of a fresh token that
     * is thrown away, keeps its original (past) expiry, and is revoked unless
     * it was accepted.
     *
     * @param  array<int, Conference>  $conferences
     * @param  array<int, User>  $users
     */
    private function importInvitations(SqlDumpReader $reader, array $conferences, array $users, ImportReport $report): void
    {
        $byEmail = [];

        foreach ($users as $user) {
            $byEmail[(string) $user->email] = $user;
        }

        foreach ($reader->rows('reviewer_invitations') as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $conference = $row['conference_id'] === null ? null : ($conferences[(int) $row['conference_id']] ?? null);

            if (! $conference instanceof Conference) {
                $report->manual->add('reviewer_invitations', $legacyId, __('legacy.review.orphan_invitation'));
                $report->recordSkipped('reviewer invitations');

                continue;
            }

            $email = $this->email($row['email'] ?? null);

            if (LegacyImport::find('reviewer_invitations', $legacyId) instanceof ReviewerInvitation) {
                $report->recordSkipped('reviewer invitations');
            } else {
                $acceptedAt = $this->dateTime($row['accepted_at'] ?? null);
                $sentAt = $this->dateTime($row['sent_at'] ?? null);
                $accepter = $acceptedAt instanceof CarbonImmutable ? ($byEmail[$email] ?? null) : null;

                $invitation = new ReviewerInvitation;
                $invitation->forceFill([
                    'conference_id' => $conference->getKey(),
                    'name' => $this->personName($row['full_name'] ?? null, $email),
                    'email' => $email,
                    'token_hash' => InvitationToken::hash(InvitationToken::generate()),
                    'invited_by' => ($users[(int) ($row['manager_id'] ?? 0)] ?? null)?->getKey(),
                    // NOT NULL in v2, and every legacy expiry is in the past
                    // anyway; the send date and then the edition's deadline are
                    // the fallbacks when the column is null.
                    'expires_at' => $this->dateTime($row['expires_at'] ?? null) ?? $sentAt ?? $this->deadlineOf($conference),
                    'accepted_at' => $acceptedAt,
                    'accepted_by' => $accepter?->getKey(),
                    // The legacy `status` column is not read at all: it is
                    // written 'Pending' against a lowercase enum, and v2 derives
                    // the state from these timestamps rather than storing it.
                    'revoked_at' => $acceptedAt instanceof CarbonImmutable ? null : now(),
                ])->save();

                $report->recordCreated('reviewer invitations');
                LegacyImport::record('reviewer_invitations', $legacyId, $invitation);
            }

            if (trim((string) ($row['token'] ?? '')) !== '') {
                // By legacy id and by address only. The token VALUE is never
                // written here: this report outlives the dump, which the
                // runbook deletes.
                $report->manual->add('reviewer_invitations', $legacyId, __('legacy.review.invitation_token_dropped', [
                    'email' => $email,
                ]));
            }
        }
    }

    /**
     * Legacy `submissions`, with their authors and their files, keyed by legacy
     * id so the reviews pass can find them again.
     *
     * ORDERED BY `submission_date`, not by the dump's own id order. Plan 3's
     * AllocateReference mints in call order and back-fills
     * `conferences.submission_counter`, so an import that ran in id order would
     * hand out a reference sequence that disagrees with the dates printed
     * beside it - and legacy's id gaps (1-4, 18 and 19 were deleted directly in
     * the database) would be baked into the numbering for ever.
     *
     * @param  array<int, Conference>  $conferences
     * @return array<int, Submission>
     */
    private function importSubmissions(SqlDumpReader $reader, array $conferences, string $uploadsPath, bool $dryRun, ImportReport $report): array
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = iterator_to_array($reader->rows('submissions'), false);

        usort($rows, function (array $a, array $b): int {
            $left = $this->dateTime($a['submission_date'] ?? null);
            $right = $this->dateTime($b['submission_date'] ?? null);

            // The id breaks a tie, so two abstracts submitted in the same
            // second still number in a stable order on a re-run.
            return [$left?->getTimestamp() ?? 0, (int) ($a['id'] ?? 0)]
                <=> [$right?->getTimestamp() ?? 0, (int) ($b['id'] ?? 0)];
        });

        $answered = $this->answeredSubmissionIds($reader);

        /** @var array<int, Submission> $submissions */
        $submissions = [];

        /** @var array<string, true> $referenced */
        $referenced = [];

        foreach ($rows as $row) {
            $legacyId = (int) ($row['id'] ?? 0);
            $conference = $row['conference_id'] === null ? null : ($conferences[(int) $row['conference_id']] ?? null);

            if ($legacyId === 0 || ! $conference instanceof Conference) {
                $report->manual->add('submissions', $legacyId, __('legacy.review.orphan_submission'));
                $report->recordSkipped('abstracts');

                continue;
            }

            $attachments = $this->attachments($row);

            foreach ($attachments as $name) {
                // Recorded even when the row is skipped, or a second run would
                // call every already-imported file an orphan.
                $referenced[$name] = true;
            }

            $mapped = LegacyImport::find('submissions', $legacyId);

            if ($mapped instanceof Submission) {
                $submissions[$legacyId] = $mapped;
                $report->recordSkipped('abstracts');

                continue;
            }

            $submission = $this->importSubmission($row, $legacyId, $conference, isset($answered[$legacyId]), $report);
            $submissions[$legacyId] = $submission;

            $this->importAuthors($row, $legacyId, $submission, $report);

            foreach ($attachments as $index => $name) {
                $this->importFile($name, $index + 1, $legacyId, $submission, $uploadsPath, $dryRun, $report);
            }
        }

        $this->reportOrphanFiles($uploadsPath, $referenced, $report);

        return $submissions;
    }

    /**
     * One abstract.
     *
     * `presentation_preference` is deliberately null: the legacy form never
     * asked, and conference 6's question 67 ("Do you recommend this abstract
     * for oral presentation") is a *reviewer's* opinion, which must not be
     * written into an *author's* preference field.
     *
     * @param  array<string, string|null>  $row
     */
    private function importSubmission(array $row, int $legacyId, Conference $conference, bool $hasAnswers, ImportReport $report): Submission
    {
        $abstract = (string) ($row['abstract'] ?? '');
        $phone = trim((string) ($row['contact_phone'] ?? ''));

        $submission = new Submission([
            // Verbatim, both of them. One real title carries PDF ligature
            // damage ("Discon7nua7on"); repairing it here would be the mapper
            // guessing at somebody's words, which is what the report is for.
            'title' => trim((string) ($row['title'] ?? '')),
            'abstract' => $abstract,
            'contact_phone' => $phone === '' ? null : Str::limit($phone, 40, ''),
            'presentation_preference' => null,
        ]);

        $submission->conference()->associate($conference);
        $submission->forceFill([
            // UnderReview when somebody actually answered a question about it,
            // Submitted when nobody did. The legacy database has no status
            // column at all, and those are the only two states its data can
            // justify - there is no decision anywhere in it.
            'status' => $hasAnswers ? SubmissionStatus::UnderReview : SubmissionStatus::Submitted,
            'word_count' => WordCounter::count($abstract),
            'reference' => $this->references->handle($conference),
            // Minted, and the plaintext thrown away. char(64) unique NOT NULL,
            // so the row cannot exist without one; nothing needs the value,
            // because an archived conference 404s /s/{token} and "Resend
            // status link" mints a new one for anybody who ever asks.
            'access_token_hash' => SubmissionToken::hash(SubmissionToken::generate()),
            // The browser's clock, which is all legacy has
            // (legacy-review.md:280).
            'submitted_at' => $this->dateTime($row['submission_date'] ?? null) ?? $this->deadlineOf($conference),
        ])->save();

        $report->recordCreated('abstracts');
        LegacyImport::record('submissions', $legacyId, $submission);

        return $submission;
    }

    /**
     * The byline, the presenter column and the one contact address, turned into
     * `submission_authors` rows.
     *
     * @param  array<string, string|null>  $row
     */
    private function importAuthors(array $row, int $legacyId, Submission $submission, ImportReport $report): void
    {
        $byline = (string) ($row['authors'] ?? '');
        $contactEmail = $this->email($row['contact_email'] ?? null);
        $affiliation = $this->affiliationFor($row, $legacyId, $byline, $report);

        $authors = AuthorList::parse(
            $byline,
            (string) ($row['presenter_names'] ?? ''),
            $contactEmail === '' ? null : $contactEmail,
            $affiliation,
        );

        if ($authors === []) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.no_authors', ['email' => $contactEmail]));

            return;
        }

        if ($contactEmail !== '' && ! AuthorList::matchedContact($byline, $contactEmail)) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.contact_unmatched', [
                'email' => $contactEmail,
                'name' => $authors[0]['name'],
            ]));
        }

        foreach ($authors as $author) {
            $email = $author['email'];

            if ($email === null || $email === '') {
                // submission_authors.email is NOT NULL and legacy stores no
                // author address at all - there is one contact_email per
                // abstract and a text blob for the byline. RFC 2606 reserves
                // .invalid precisely so a value like this can never resolve
                // and can never be delivered to by accident. Relaxing the
                // column instead would weaken a constraint for every real
                // submission afterwards, to fit twenty-four legacy rows.
                $email = 'legacy-'.$legacyId.'-'.$author['sort'].'@import.invalid';

                $report->manual->add('submissions', $legacyId, __('legacy.review.invented_email', [
                    'name' => $author['name'],
                    'email' => $email,
                ]));
            }

            $record = new SubmissionAuthor([
                'name' => Str::limit($author['name'], 180, ''),
                'email' => $email,
                'affiliation' => $author['affiliation'],
                'is_presenter' => $author['is_presenter'],
                'is_corresponding' => $author['is_corresponding'],
                'sort' => $author['sort'],
            ]);

            $record->submission()->associate($submission);
            $record->save();

            $report->recordCreated('authors');
        }
    }

    /**
     * The 2023 affiliation swap, handled by one narrow rule that reports every
     * row it touches - the values it keeps as well as the values it drops.
     *
     * For conference 5 the column holds a person's name in most rows, the whole
     * author list in two, and a research question in one; conference 6's rows
     * are clean institutional strings. AuthorList::looksLikeAffiliation() can
     * only recognise the person-shaped case, so the research question comes
     * back true - which is exactly why a KEPT value is reported too, rather
     * than the mapper pretending it knew. It never deletes silently: a dropped
     * value reaches the report with its row and its string.
     *
     * @param  array<string, string|null>  $row
     */
    private function affiliationFor(array $row, int $legacyId, string $byline, ImportReport $report): ?string
    {
        $value = trim((string) ($row['affiliation'] ?? ''));

        if ($value === '') {
            return null;
        }

        if (! AuthorList::looksLikeAffiliation($value, $byline)) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.affiliation_dropped', ['value' => $value]));

            return null;
        }

        $report->manual->add('submissions', $legacyId, __('legacy.review.affiliation_kept', ['value' => $value]));

        return $value;
    }

    /**
     * `attachment`, split on commas.
     *
     * The review documents that column as a comma-separated list
     * (legacy-review.md:279) even though all twenty-four rows hold exactly one
     * path, and a row with two must not silently lose one. `basename()`,
     * because the uploads directory in the legacy zip is flat.
     *
     * @param  array<string, string|null>  $row
     * @return list<string>
     */
    private function attachments(array $row): array
    {
        $names = [];

        foreach (explode(',', (string) ($row['attachment'] ?? '')) as $path) {
            $name = basename(trim(str_replace('\\', '/', $path)));

            if ($name !== '' && $name !== '.' && $name !== '..') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * One legacy PDF, written into the same content-addressed layout
     * StoreSubmissionFile uses - but NOT through it.
     *
     * That action takes an `UploadedFile` and runs the upload-time gate: size,
     * count, extension and sniffed MIME. That is the right gate for a
     * stranger's POST and the wrong one for the platform owner's own archive: a
     * legacy file over `CASS_MAX_FILE_BYTES`, or one whose magic bytes sniff
     * oddly, has to be imported and REPORTED rather than refused. There is no
     * `UploadedFile` here to hand it either.
     */
    private function importFile(string $name, int $sort, int $legacyId, Submission $submission, string $uploadsPath, bool $dryRun, ImportReport $report): void
    {
        $source = rtrim($uploadsPath, '/\\').DIRECTORY_SEPARATOR.$name;

        if (! is_file($source) || ! is_readable($source)) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.file_missing', ['file' => $name]));

            return;
        }

        $sha = hash_file('sha256', $source);
        $size = filesize($source);

        if ($sha === false || $size === false) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.file_unreadable', ['file' => $name]));

            return;
        }

        if ($submission->files()->where('sha256', $sha)->exists()) {
            // unique(submission_id, sha256): the same bytes twice on one
            // abstract is one file the legacy row named twice, not two files.
            $report->manual->add('submissions', $legacyId, __('legacy.review.file_duplicate', ['file' => $name]));

            return;
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $extension = $extension === '' ? 'pdf' : $extension;
        $mime = SniffedMimeType::forPath($source);

        if (! SniffedMimeType::matches($mime, $extension)) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.file_mime', [
                'file' => $name,
                'mime' => $mime ?? 'unknown',
                'extension' => $extension,
            ]));
        }

        $limit = (int) config('cass.max_file_bytes');

        if ($size > $limit) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.file_oversize', [
                'file' => $name,
                'bytes' => (string) $size,
                'limit' => (string) $limit,
            ]));
        }

        $ulid = (string) Str::ulid();
        // {first 2 of sha256}/{ulid}.{ext}, exactly what StoreSubmissionFile
        // writes, so the signed download route and both purges work on an
        // imported file without knowing it was imported.
        $path = substr($sha, 0, 2).'/'.$ulid.'.'.$extension;

        // A dry run writes no object. Everything above it - the digest, the
        // sniff, the size, the row and every constraint on it - really runs,
        // inside the transaction that is about to be rolled back; the disk is
        // the one thing no rollback could undo.
        if (! $dryRun && ! $this->copyToPrivateDisk($source, $path)) {
            $report->manual->add('submissions', $legacyId, __('legacy.review.file_unwritable', ['file' => $name]));

            return;
        }

        $record = new SubmissionFile;
        $record->forceFill([
            'submission_id' => $submission->getKey(),
            'ulid' => $ulid,
            // The legacy basename. The name the author typed was thrown away
            // at upload in 2023 and is unrecoverable.
            'original_name' => $name,
            'path' => $path,
            'mime' => $mime ?? 'application/octet-stream',
            'size' => $size,
            'sha256' => $sha,
            'sort' => $sort,
        ])->save();

        $report->recordCreated('files');
    }

    private function copyToPrivateDisk(string $source, string $path): bool
    {
        $stream = @fopen($source, 'rb');

        if ($stream === false) {
            return false;
        }

        try {
            // The `local` disk is configured throw=false, report=false
            // (config/filesystems.php), so a write onto a full or read-only
            // volume comes back as a bare false with nothing logged - and a
            // live row whose bytes are not there is worse than a reported
            // failure.
            return Storage::disk('local')->writeStream($path, $stream) !== false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Every file in the uploads directory that no row referenced - the residue
     * of abstracts deleted directly in the legacy database (three of them in
     * the real zip). They cannot be imported at all:
     * `submission_files.submission_id` is NOT NULL.
     *
     * @param  array<string, true>  $referenced
     */
    private function reportOrphanFiles(string $uploadsPath, array $referenced, ImportReport $report): void
    {
        $entries = @scandir($uploadsPath);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset($referenced[$entry])) {
                continue;
            }

            if (! is_file(rtrim($uploadsPath, '/\\').DIRECTORY_SEPARATOR.$entry)) {
                continue;
            }

            $report->manual->add('uploads', 0, __('legacy.review.file_orphan', ['file' => $entry]));
        }
    }

    /**
     * Legacy `reviews` rows, grouped by (submission_id, reviewer_id).
     *
     * There is no review header row in the legacy database at all - no status,
     * no timestamp, no draft concept - so a "review" exists only as the set of
     * answer rows sharing a submission and a reviewer. Every one is imported
     * SUBMITTED, including the two that are incomplete: marking those draft
     * would drop them out of Plan 5's `review_count`, out of the mean, and out
     * of the record of what the committee was actually given. ReviewScorer
     * computes a weighted mean over the answers that exist, so an 8-of-9 review
     * still produces a number.
     *
     * @param  array<int, Submission>  $submissions
     * @param  array<int, User>  $users
     */
    private function importReviews(SqlDumpReader $reader, array $submissions, array $users, ImportReport $report): void
    {
        /** @var array<string, list<array<string, string|null>>> $groups */
        $groups = [];

        foreach ($reader->rows('reviews') as $row) {
            $groups[(int) ($row['submission_id'] ?? 0).':'.(int) ($row['reviewer_id'] ?? 0)][] = $row;
        }

        if ($groups !== []) {
            // ONE line, not one per review: there is no legacy timestamp
            // anywhere in the schema, so this is a property of the import
            // rather than of a row, and ninety identical lines is a report
            // nobody finishes reading.
            $report->manual->add('reviews', 0, __('legacy.review.review_timestamps_synthetic'));
        }

        /** @var array<int, ReviewForm|null> $forms */
        $forms = [];

        foreach ($groups as $key => $rows) {
            $parts = explode(':', (string) $key);
            $legacySubmissionId = (int) $parts[0];
            $legacyReviewerId = (int) ($parts[1] ?? 0);

            $submission = $submissions[$legacySubmissionId] ?? null;
            $user = $users[$legacyReviewerId] ?? null;

            if (! $submission instanceof Submission || ! $user instanceof User) {
                $report->manual->add('reviews', $legacySubmissionId, __('legacy.review.orphan_review', [
                    'reviewer' => (string) $legacyReviewerId,
                    'answers' => (string) count($rows),
                ]));
                $report->recordSkipped('reviews');

                continue;
            }

            $conferenceId = (int) $submission->conference_id;

            if (! array_key_exists($conferenceId, $forms)) {
                $forms[$conferenceId] = ReviewForm::query()->where('conference_id', $conferenceId)->orderBy('id')->first();
            }

            $form = $forms[$conferenceId];

            if (! $form instanceof ReviewForm) {
                $report->manual->add('reviews', $legacySubmissionId, __('legacy.review.orphan_review', [
                    'reviewer' => (string) $legacyReviewerId,
                    'answers' => (string) count($rows),
                ]));
                $report->recordSkipped('reviews');

                continue;
            }

            // Idempotent by the unique (submission_id, reviewer_user_id) pair
            // rather than by a legacy_imports mapping: a legacy "review" has no
            // row and therefore no id of its own to map.
            $existing = Review::query()
                ->where('submission_id', $submission->getKey())
                ->where('reviewer_user_id', $user->getKey())
                ->first();

            if ($existing instanceof Review) {
                $report->recordSkipped('reviews');

                continue;
            }

            $review = new Review;
            $review->forceFill([
                'submission_id' => $submission->getKey(),
                'reviewer_user_id' => $user->getKey(),
                'review_form_id' => $form->getKey(),
                'status' => ReviewStatus::Submitted,
                // Synthetic, and reported once above: the abstract's own date
                // plus a day, so a review is never dated before the thing it
                // reviews - which a conference-level date could not promise,
                // because one real abstract was accepted after its
                // conference's deadline.
                'submitted_at' => $this->reviewedAt($submission),
            ])->save();

            $report->recordCreated('reviews');

            $this->importAnswers($rows, $review, $form, $legacySubmissionId, $report);
        }
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private function importAnswers(array $rows, Review $review, ReviewForm $form, int $legacySubmissionId, ImportReport $report): void
    {
        $answered = 0;

        foreach ($rows as $row) {
            $legacyQuestionId = (int) ($row['question_id'] ?? 0);
            $question = LegacyImport::find('evaluation_questions', $legacyQuestionId);

            if (! $question instanceof ReviewQuestion || (int) $question->review_form_id !== (int) $form->getKey()) {
                $report->manual->add('reviews', $legacySubmissionId, __('legacy.review.orphan_answer', [
                    'question' => (string) $legacyQuestionId,
                ]));

                continue;
            }

            if ($review->answers()->where('review_question_id', $question->getKey())->exists()) {
                $answered++;

                continue;
            }

            $value = trim((string) ($row['answer'] ?? ''));
            $isLikert = $question->type === ReviewQuestionType::Likert;
            $numeric = $isLikert && $value !== '' && ctype_digit($value);

            if ($isLikert && ! $numeric && $value !== '') {
                $report->manual->add('reviews', $legacySubmissionId, __('legacy.review.answer_not_numeric', [
                    'value' => $value,
                ]));
            }

            $answer = new ReviewAnswer;
            $answer->forceFill([
                'review_id' => $review->getKey(),
                'review_question_id' => $question->getKey(),
                // Every legacy answer is a digit 1-5 in a text column, and the
                // QUESTION's type decides which v2 column means anything -
                // AnswerNormaliser reads by type and never by "the first
                // column that is not null".
                'value_int' => $numeric ? (int) $value : null,
                'value_text' => $isLikert ? null : ($value === '' ? null : $value),
                'value_bool' => null,
                'choice_key' => null,
            ])->save();

            $report->recordCreated('review answers');
            $answered++;
        }

        $total = $form->questions()->count();

        if ($answered < $total) {
            $report->manual->add('reviews', $legacySubmissionId, __('legacy.review.review_incomplete', [
                'answered' => (string) $answered,
                'total' => (string) $total,
            ]));
        }
    }

    private function reviewedAt(Submission $submission): CarbonImmutable
    {
        $submitted = $submission->submitted_at;

        return ($submitted === null ? CarbonImmutable::now() : CarbonImmutable::parse($submitted))->addDay();
    }

    /**
     * Which legacy abstracts anybody answered a question about - the only thing
     * in that database that can tell `under_review` from `submitted`.
     *
     * @return array<int, true>
     */
    private function answeredSubmissionIds(SqlDumpReader $reader): array
    {
        $answered = [];

        foreach ($reader->rows('reviews') as $row) {
            if ($row['submission_id'] !== null) {
                $answered[(int) $row['submission_id']] = true;
            }
        }

        return $answered;
    }

    /**
     * The last two steps, in this order and no other.
     *
     * `review_forms.locked_at` is stamped AFTER every question and every answer
     * exists: ReviewQuestion::booted()'s `updating` hook throws
     * ReviewFormLocked on a locked form, so a form locked before its questions
     * are final could not be corrected. The update goes through the query
     * builder, which fires no model event.
     *
     * The scores are then RECOMPUTED rather than carried. Legacy's "Total
     * Score" was an un-normalised SUM across reviewers and questions
     * (legacy-review.md:267) and is not comparable to anything v2 computes;
     * ComputeSubmissionScore::forConference() is the second of the four uses
     * RescoreConferenceCommand's own docblock lists.
     *
     * @param  array<int, Conference>  $conferences
     */
    private function lockAndScore(array $conferences): void
    {
        foreach ($conferences as $conference) {
            ReviewForm::query()
                ->where('conference_id', $conference->getKey())
                ->whereNull('locked_at')
                ->update(['locked_at' => now(), 'updated_at' => now()]);

            $this->scores->forConference($conference);
        }
    }

    /**
     * The legacy invitations' `accepted_at` and `sent_at`, folded by address -
     * the only record of when a reviewer joined a conference.
     *
     * @return array{0: array<string, CarbonImmutable>, 1: array<string, CarbonImmutable>}
     */
    private function invitationDates(SqlDumpReader $reader): array
    {
        $accepted = [];
        $sent = [];

        foreach ($reader->rows('reviewer_invitations') as $row) {
            $email = $this->email($row['email'] ?? null);

            if ($email === '') {
                continue;
            }

            $acceptedAt = $this->dateTime($row['accepted_at'] ?? null);
            $sentAt = $this->dateTime($row['sent_at'] ?? null);

            if ($acceptedAt instanceof CarbonImmutable) {
                $accepted[$email] = $acceptedAt;
            }

            if ($sentAt instanceof CarbonImmutable) {
                $sent[$email] = $sentAt;
            }
        }

        return [$accepted, $sent];
    }

    private function deadlineOf(Conference $conference): CarbonImmutable
    {
        $deadline = $conference->submission_deadline;

        return $deadline === null ? CarbonImmutable::now() : CarbonImmutable::parse($deadline);
    }

    private function writeReport(ImportReport $report, Organization $organization): string
    {
        $directory = trim((string) config('cass.legacy.report_directory'), '/');
        $path = ($directory === '' ? '' : $directory.'/').'manual-review-'.now()->format('Ymd-His').'.md';

        Storage::disk('local')->put($path, $report->manual->toMarkdown((string) $organization->name));

        return $path;
    }

    private function email(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /** The legacy `full_name`, or the mailbox in front of the @ when there is none. */
    private function personName(?string $fullName, string $email): string
    {
        $name = trim((string) $fullName);

        if ($name !== '') {
            return $name;
        }

        $local = strstr($email, '@', true);

        return $local === false ? $email : $local;
    }

    private function dateTime(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        // MySQL's zero date is not a date, and Carbon parses it into year zero.
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
