<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

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
use App\Models\ReviewerReminder;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use App\Models\Submission;
use App\Models\SubmissionAuthor;
use App\Models\SubmissionDecision;
use App\Models\SubmissionFile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One conference and everything under it, removed for good, in application
 * code - the conference-scoped half of the purge the backlog asked Plan 6 for:
 * "purge one conference of a tenant that keeps its others".
 *
 * This is where the ORDER lives. App\Actions\Organizations\PurgeOrganization
 * calls rows() per conference rather than repeating it, because the order is
 * the one thing in this application that must not exist twice: eleven foreign
 * keys are RESTRICT and four tables carry no key at all, and a second copy
 * that drifts is a QueryException in a platform admin's face at the worst
 * possible moment.
 *
 * Every delete is a MASS delete through the query builder, so no model event
 * fires: nothing writes an activity entry per row, and ReviewQuestion's
 * `deleting` guard against a locked form does not refuse - a purge is exactly
 * the case that guard is not about, and a conference that ever had a submitted
 * review has a locked form for ever.
 *
 * activity_log is deliberately untouched. See the class docblock on
 * PurgeOrganization and Plan 6 Task 4's decision 3: the audit trail of who
 * deleted what is the last thing a purge should erase, and a GDPR erasure
 * request is a person-shaped problem with a documented manual query in the
 * runbook.
 */
final class PurgeConference
{
    /**
     * Delete the tree, then the objects, then write the audit entry.
     *
     * @return array<string, int> what went, keyed by table, plus 'private files'
     */
    public function handle(Conference $conference, User $actor): array
    {
        if (! Conference::withTrashed()->whereKey($conference->getKey())->exists()) {
            return [];
        }

        // Read before the row goes, and withTrashed() because an organization
        // soft-deletes while its conferences survive - the belongsTo accessor
        // would answer null for exactly the tenant whose audit entry matters.
        $organization = Organization::withTrashed()
            ->whereKey($conference->organization_id)
            ->first();

        /** @var list<string> $paths */
        $paths = [];

        /** @var array<string, int> $counts */
        $counts = DB::transaction(function () use ($conference, &$paths): array {
            [$counts, $paths] = $this->rows($conference);

            return $counts;
        });

        // After the commit, never inside it. DeleteSubmissionFile's docblock
        // is the argument: a live row whose bytes are gone is the state to
        // avoid, and an orphan object nothing references is the failure this
        // chooses instead. A nested transaction would be a savepoint that
        // rolls back while the Storage::delete already ran.
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        $counts['private files'] = count($paths);

        if ($organization instanceof Organization) {
            // Performed on the organization: the conference is gone, and this
            // is the one record that the purge happened.
            activity()
                ->performedOn($organization)
                ->causedBy($actor)
                ->withProperties([
                    'conference_ulid' => (string) $conference->ulid,
                    'conference_name' => (string) $conference->name,
                    'rows' => $counts,
                ])
                ->log('conference.purged');
        }

        return $counts;
    }

    /**
     * What handle() would delete, without deleting it - the count-per-table
     * preview the confirmation modal shows *before* anything happens.
     *
     * It runs the same queries with ->count() instead of ->delete(), from the
     * same private list, and repeats rows()'s two explicit soft-deleting tables
     * in the same positions, so the two cannot describe different trees.
     *
     * @return array<string, int>
     */
    public function preview(Conference $conference): array
    {
        $ids = $this->collect($conference);
        $counts = [];

        foreach ($this->queries($conference, $ids) as $table => $query) {
            $counts[$table] = $query->count();
        }

        // By hand, exactly where rows() force-deletes it, so the two arrays
        // carry the same keys in the same order.
        $counts['submissions'] = Submission::withTrashed()->whereIn('id', $ids['submissions'])->count();
        $counts['conferences'] = Conference::withTrashed()->whereKey($conference->getKey())->count();
        $counts['private files'] = count($ids['paths']);

        return $counts;
    }

    /**
     * The rows, in order, INSIDE a caller's transaction. Returns the counts and
     * the paths whose objects the caller must delete after it commits.
     *
     * PurgeOrganization calls this directly; nothing else should.
     *
     * @return array{0: array<string, int>, 1: list<string>}
     */
    public function rows(Conference $conference): array
    {
        $ids = $this->collect($conference);
        $counts = [];

        foreach ($this->queries($conference, $ids) as $table => $query) {
            $counts[$table] = $query->delete();
        }

        // The one soft-deleting table inside the subtree, and the reason it is
        // not in queries(): ->delete() on a SoftDeletes builder is an UPDATE.
        // forceDelete() is a real DELETE, and it is safe here because every
        // RESTRICT onto submissions went in the loop above and conferences -
        // the only RESTRICT submissions is under - goes next.
        $counts['submissions'] = Submission::withTrashed()->whereIn('id', $ids['submissions'])->forceDelete();

        // Last, and through the model, so anything observing a conference sees
        // it go. withTrashed(): the table soft-deletes.
        $counts['conferences'] = Conference::withTrashed()->whereKey($conference->getKey())->forceDelete();

        return [$counts, $ids['paths']];
    }

    /**
     * Every id the purge needs, read once and up front: after the first delete
     * the rows that would answer these questions are gone.
     *
     * @return array{submissions: list<int>, reviews: list<int>, forms: list<int>, questions: list<int>, conferenceReviewers: list<int>, reviewerInvitations: list<int>, shortLinks: list<int>, paths: list<string>}
     */
    private function collect(Conference $conference): array
    {
        $conferenceId = (int) $conference->getKey();

        $submissions = $this->ids(Submission::withTrashed()->where('conference_id', $conferenceId));
        $forms = $this->ids(ReviewForm::query()->where('conference_id', $conferenceId));
        $reviews = $this->ids(Review::query()->whereIn('submission_id', $submissions));
        $shortLinks = $this->ids(ShortLink::query()
            ->where('target_type', (new Conference)->getMorphClass())
            ->where('target_id', $conferenceId));

        /** @var list<string> $paths */
        $paths = SubmissionFile::query()
            ->whereIn('submission_id', $submissions)
            ->pluck('path')
            ->map(static fn (mixed $path): string => (string) $path)
            ->all();

        // Read up front like everything else here, and needed by exactly one
        // line of queries(): the legacy_imports sweep, whose targets are
        // polymorphic and therefore unfindable once the rows they point at are
        // gone.
        $questions = $this->ids(ReviewQuestion::query()->whereIn('review_form_id', $forms));
        $conferenceReviewers = $this->ids(ConferenceReviewer::query()->where('conference_id', $conferenceId));
        $reviewerInvitations = $this->ids(ReviewerInvitation::query()->where('conference_id', $conferenceId));

        return [
            'submissions' => $submissions,
            'reviews' => $reviews,
            'forms' => $forms,
            'questions' => $questions,
            'conferenceReviewers' => $conferenceReviewers,
            'reviewerInvitations' => $reviewerInvitations,
            'shortLinks' => $shortLinks,
            'paths' => $paths,
        ];
    }

    /**
     * THE ORDER. One entry per table, deepest first, each one a query that
     * preview() counts and rows() deletes. Adding a table to the schema means
     * adding a line here; Plan 6 Task 14 Step 8 greps the migrations against
     * this method.
     *
     * @param  array{submissions: list<int>, reviews: list<int>, forms: list<int>, questions: list<int>, conferenceReviewers: list<int>, reviewerInvitations: list<int>, shortLinks: list<int>, paths: list<string>}  $ids
     * @return array<string, Builder<covariant \Illuminate\Database\Eloquent\Model>>
     */
    private function queries(Conference $conference, array $ids): array
    {
        $conferenceId = (int) $conference->getKey();

        return [
            // review_answers.review_question_id RESTRICTs the questions below.
            'review_answers' => ReviewAnswer::query()->whereIn('review_id', $ids['reviews']),
            // RESTRICT onto submissions, users AND review_forms.
            'reviews' => Review::query()->whereIn('id', $ids['reviews']),
            // RESTRICT onto submissions: the record of what an author was told.
            'submission_decisions' => SubmissionDecision::query()->whereIn('submission_id', $ids['submissions']),
            // Cascade, counted: an admin destroying a conference is entitled to
            // know it is destroying 412 authors.
            'review_assignments' => ReviewAssignment::query()->whereIn('submission_id', $ids['submissions']),
            'submission_files' => SubmissionFile::query()->whereIn('submission_id', $ids['submissions']),
            'submission_authors' => SubmissionAuthor::query()->whereIn('submission_id', $ids['submissions']),
            // Before the rows they point at: all three keys are nullOnDelete,
            // so the alternative to deleting is anonymising, not keeping. A row
            // that is only the organization's survives until the organization
            // is purged.
            'email_logs' => EmailLog::query()
                ->where(function (Builder $query) use ($conferenceId, $ids): void {
                    $query->where('conference_id', $conferenceId)
                        ->orWhereIn('submission_id', $ids['submissions']);
                }),
            // `submissions` is DELIBERATELY absent from this list. The table
            // soft-deletes, so the uniform ->delete() loop in rows() would only
            // set deleted_at on rows that already carry it and leave every
            // RESTRICT above pointless. It is force-deleted by hand in rows()
            // and counted by hand in preview(), after this loop and before the
            // conference row - by which point every RESTRICT onto submissions
            // (reviews, submission_decisions) is gone and the only RESTRICT
            // submissions itself sits under, conferences, has not run yet.
            'reviewer_reminders' => ReviewerReminder::query()->where('conference_id', $conferenceId),
            'conference_reviewers' => ConferenceReviewer::query()->where('conference_id', $conferenceId),
            'reviewer_invitations' => ReviewerInvitation::query()->where('conference_id', $conferenceId),
            'email_templates' => EmailTemplate::query()->where('conference_id', $conferenceId),
            // THE line the model hook would have thrown on.
            'review_questions' => ReviewQuestion::query()->whereIn('review_form_id', $ids['forms']),
            'review_forms' => ReviewForm::query()->whereIn('id', $ids['forms']),
            // submissions.track_id is nullOnDelete, so the order against the
            // submissions line above is free.
            'custom_fields' => CustomField::query()->where('conference_id', $conferenceId),
            'tracks' => Track::query()->where('conference_id', $conferenceId),
            // No foreign key, like short_links and activity_log: the mapping
            // rows would otherwise outlive their targets, and a re-import would
            // find a mapping to a row that no longer exists and skip a
            // conference it should have created. Everything is inside ONE
            // where() closure - a bare orWhere() here escapes the surrounding
            // scope and matches every mapping in the database, which is what
            // the parity test catches.
            //
            // `users` is deliberately NOT swept: no purge in this application
            // deletes a User row (PurgeOrganization ends at
            // organization_members), so the mapping is still true after the
            // purge and deleting it would only make a re-import re-adopt the
            // same account by address for no reason.
            'legacy_imports' => LegacyImport::query()->where(function (Builder $query) use ($conferenceId, $ids): void {
                $query->where(fn (Builder $q) => $q->where('imported_type', (new Conference)->getMorphClass())->where('imported_id', $conferenceId))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new Submission)->getMorphClass())->whereIn('imported_id', $ids['submissions']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ReviewForm)->getMorphClass())->whereIn('imported_id', $ids['forms']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ReviewQuestion)->getMorphClass())->whereIn('imported_id', $ids['questions']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ConferenceReviewer)->getMorphClass())->whereIn('imported_id', $ids['conferenceReviewers']))
                    ->orWhere(fn (Builder $q) => $q->where('imported_type', (new ReviewerInvitation)->getMorphClass())->whereIn('imported_id', $ids['reviewerInvitations']));
            }),
            // NO foreign key reaches either: nothing would remove them, and
            // unique(target_type, target_id) would then refuse a conference
            // re-created at the same id.
            'short_link_visits' => ShortLinkVisit::query()->whereIn('short_link_id', $ids['shortLinks']),
            'short_links' => ShortLink::query()->whereIn('id', $ids['shortLinks']),
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<int>
     */
    private function ids(Builder $query): array
    {
        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }
}
