<?php

declare(strict_types=1);

namespace App\Actions\Legacy;

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReviewerStatus;
use App\Enums\ReviewMode;
use App\Enums\ReviewQuestionType;
use App\Exceptions\LegacyImportRefused;
use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\LegacyImport;
use App\Models\Organization;
use App\Models\ReviewerInvitation;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\User;
use App\Support\Legacy\ImportReport;
use App\Support\Legacy\SqlDumpReader;
use App\Support\Submissions\ReferencePrefix;
use App\Support\Tokens\InvitationToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The legacy CASS database, mapped into this one (spec 5.10).
 *
 * This half does everything above an abstract: the organization's membership,
 * the two conferences, the users, the review forms and their questions, the
 * reviewer memberships and the invitations. Abstracts, authors, files and
 * reviews are the second half.
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
            $this->run($reader, $organization, $report);

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

        // Outside the transaction, and written for a dry run too: the whole
        // point of a dry run is to read this file before the real one.
        $report->reportPath = $this->writeReport($report, $organization);

        return $report;
    }

    private function run(SqlDumpReader $reader, Organization $organization, ImportReport $report): void
    {
        $users = $this->importUsers($reader, $report);
        $this->importMembers($reader, $organization, $users, $report);
        $conferences = $this->importConferences($reader, $organization, $report);
        $this->importReviewForms($reader, $conferences, $report);
        $this->importReviewers($reader, $conferences, $users, $report);
        $this->importInvitations($reader, $conferences, $users, $report);
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
