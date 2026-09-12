<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Conference;
use App\Models\ConferenceReviewer;
use App\Models\CustomField;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The hard purge the backlog has been describing since Plan 1: one organization
 * and everything under it, removed for good, in application code.
 *
 * **Why it cannot be a cascade.** Spec section 3 makes ordinary deletion a soft
 * delete, so the foreign keys from `conferences`, `submissions`, `tracks`,
 * `custom_fields`, `review_forms`, `review_questions`, `reviews` and
 * `submission_decisions` are all `RESTRICT` - deliberately, so that a stray
 * `forceDelete()` cannot silently wipe a tenant and, worse, leave the objects
 * on the private disk behind with nothing left that references them. The order
 * below is the topological sort of those constraints, read off the migrations,
 * and it is the reason this is a class and not a `->forceDelete()`.
 *
 * **The disk comes last, outside the transaction**, for the reason
 * DeleteSubmissionFile spells out: removing an object first and rolling the
 * rows back leaves live rows whose bytes are gone, and no rollback can put a
 * deleted object back. An orphaned object is the half of the pair worth
 * risking, because content addressing means it belongs to exactly one row and
 * nothing will ever reference it again.
 *
 * Every delete here is a **mass** delete through the query builder, so no model
 * event fires: nothing writes an activity entry per row, ReviewQuestion's
 * `deleting` guard against a locked form does not refuse (a purge is exactly
 * the case that guard is not about), and a conference with five hundred
 * abstracts is a few dozen statements rather than tens of thousands.
 *
 * `activity_log` is **not** touched. Its subject and causer columns are
 * nullable morphs with no foreign key, so the rows survive harmlessly, and the
 * audit trail of who deleted what is the last thing a purge should erase.
 *
 * Written for `cass:demo-reset`, deliberately general: Plan 6 puts the same
 * action behind a platform-admin screen.
 */
final class PurgeOrganization
{
    /**
     * @return array<string, int> what was deleted, keyed by table, in the order
     *                            the rows went
     */
    public function handle(Organization $organization): array
    {
        $organizationId = (int) $organization->getKey();

        // Every id the purge needs, read once and up front: after the first
        // delete the rows that would answer these questions are gone.
        $conferenceIds = $this->ids(Conference::withTrashed()->where('organization_id', $organizationId));
        $submissionIds = $this->ids(Submission::withTrashed()->whereIn('conference_id', $conferenceIds));
        $reviewFormIds = $this->ids(ReviewForm::query()->whereIn('conference_id', $conferenceIds));
        $reviewIds = $this->ids(Review::query()->whereIn('submission_id', $submissionIds));
        $shortLinkIds = $this->ids($this->shortLinks($organization, $conferenceIds));

        /** @var list<string> $paths */
        $paths = SubmissionFile::query()
            ->whereIn('submission_id', $submissionIds)
            ->pluck('path')
            ->map(static fn (mixed $path): string => (string) $path)
            ->all();

        /** @var array<string, int> $counts */
        $counts = DB::transaction(function () use (
            $organizationId,
            $conferenceIds,
            $submissionIds,
            $reviewFormIds,
            $reviewIds,
            $shortLinkIds,
        ): array {
            $counts = [];

            // The submission tree, deepest first. review_answers cascades from
            // reviews, but it is deleted explicitly because
            // review_answers.review_question_id RESTRICTs the questions further
            // down and the count belongs in the report either way.
            $counts['review_answers'] = ReviewAnswer::query()->whereIn('review_id', $reviewIds)->delete();
            $counts['reviews'] = Review::query()->whereIn('id', $reviewIds)->delete();
            $counts['submission_decisions'] = SubmissionDecision::query()->whereIn('submission_id', $submissionIds)->delete();
            $counts['review_assignments'] = ReviewAssignment::query()->whereIn('submission_id', $submissionIds)->delete();
            $counts['submission_files'] = SubmissionFile::query()->whereIn('submission_id', $submissionIds)->delete();
            $counts['submission_authors'] = SubmissionAuthor::query()->whereIn('submission_id', $submissionIds)->delete();

            // Before the submissions and the conferences: email_logs points at
            // all three with nullOnDelete, so leaving them would turn a tenant's
            // whole mail history into unattributed rows in the admin panel
            // rather than removing it.
            $counts['email_logs'] = EmailLog::query()
                ->where(function (Builder $query) use ($organizationId, $conferenceIds, $submissionIds): void {
                    $query->where('organization_id', $organizationId)
                        ->orWhereIn('conference_id', $conferenceIds)
                        ->orWhereIn('submission_id', $submissionIds);
                })
                ->delete();

            $counts['submissions'] = Submission::withTrashed()->whereIn('id', $submissionIds)->forceDelete();

            // The conference tree.
            $counts['reviewer_reminders'] = ReviewerReminder::query()->whereIn('conference_id', $conferenceIds)->delete();
            $counts['conference_reviewers'] = ConferenceReviewer::query()->whereIn('conference_id', $conferenceIds)->delete();
            $counts['reviewer_invitations'] = ReviewerInvitation::query()->whereIn('conference_id', $conferenceIds)->delete();
            $counts['email_templates'] = EmailTemplate::query()->whereIn('conference_id', $conferenceIds)->delete();
            $counts['review_questions'] = ReviewQuestion::query()->whereIn('review_form_id', $reviewFormIds)->delete();
            $counts['review_forms'] = ReviewForm::query()->whereIn('id', $reviewFormIds)->delete();
            $counts['custom_fields'] = CustomField::query()->whereIn('conference_id', $conferenceIds)->delete();
            $counts['tracks'] = Track::query()->whereIn('conference_id', $conferenceIds)->delete();
            $counts['short_link_visits'] = ShortLinkVisit::query()->whereIn('short_link_id', $shortLinkIds)->delete();
            $counts['short_links'] = ShortLink::query()->whereIn('id', $shortLinkIds)->delete();
            $counts['conferences'] = Conference::withTrashed()->whereIn('id', $conferenceIds)->forceDelete();

            // The organization itself.
            $counts['organization_invitations'] = OrganizationInvitation::query()->where('organization_id', $organizationId)->delete();
            $counts['organization_members'] = OrganizationMember::query()->where('organization_id', $organizationId)->delete();
            $counts['organizations'] = Organization::withTrashed()->whereKey($organizationId)->forceDelete();

            return $counts;
        });

        foreach ($paths as $path) {
            // The bool is deliberately unchecked, exactly as in
            // DeleteSubmissionFile: the row is already gone, so there is
            // nothing left to refuse for.
            Storage::disk('local')->delete($path);
        }

        $counts['private files'] = count($paths);

        return $counts;
    }

    /**
     * Short links are polymorphic and carry no foreign key, so nothing in the
     * database removes them with their target. Conferences are the only thing
     * that has one today (PublishConference); the organization arm is here
     * because `short_links.target_type` is a morph and the next target added
     * would otherwise leave a live `/q/{code}` pointing at a deleted tenant.
     *
     * @param  list<int>  $conferenceIds
     * @return Builder<ShortLink>
     */
    private function shortLinks(Organization $organization, array $conferenceIds): Builder
    {
        return ShortLink::query()
            ->where(function (Builder $query) use ($conferenceIds): void {
                $query->where('target_type', (new Conference)->getMorphClass())
                    ->whereIn('target_id', $conferenceIds);
            })
            ->orWhere(function (Builder $query) use ($organization): void {
                $query->where('target_type', $organization->getMorphClass())
                    ->where('target_id', $organization->getKey());
            });
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
