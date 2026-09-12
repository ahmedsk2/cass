<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Actions\Conferences\PurgeConference;
use App\Models\Conference;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use App\Models\Submission;
use App\Models\User;
use App\Support\Domains\CustomDomains;
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
 * **The conference subtree is not repeated here.**
 * App\Actions\Conferences\PurgeConference owns that order and this class calls
 * it once per conference, because the order is the one thing in this
 * application that must not exist twice: a second copy that drifts is a
 * QueryException in a platform admin's face at the worst possible moment. What
 * is left below is the organization's own rows - the tenant-level mail history,
 * the memberships, the invitations, the organization-targeted short links and
 * the row itself.
 *
 * **The disk comes last, outside the transaction**, for the reason
 * DeleteSubmissionFile spells out: removing an object first and rolling the
 * rows back leaves live rows whose bytes are gone, and no rollback can put a
 * deleted object back. An orphaned object is the half of the pair worth
 * risking, because content addressing means it belongs to exactly one row and
 * nothing will ever reference it again. That is also why PurgeConference has
 * two entry points: rows() deletes rows and *returns* the paths, so this class
 * can do one disk pass after its own transaction commits.
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
 * `legacy_imports` needs **no line of its own here**, and that is a consequence
 * of the delegation above rather than an omission: every mapping row the legacy
 * import writes points at a conference or at something inside one, so
 * PurgeConference's own sweep removes them per conference. The one kind that is
 * not conference-scoped is the `users` mapping - and that one is deliberately
 * kept by both classes, because no purge in this application deletes a `User`
 * row (this one ends at `organization_members`), so the mapping is still true
 * afterwards and dropping it would only make a re-import re-adopt the same
 * account by address for no reason.
 *
 * Written for `cass:demo-reset`, deliberately general: Plan 6 puts the same
 * action behind a platform-admin screen, which is where the optional $actor
 * comes from.
 */
final class PurgeOrganization
{
    public function __construct(private readonly PurgeConference $conferences) {}

    /**
     * @return array<string, int> what was deleted, keyed by table, in the order
     *                            the rows went
     */
    public function handle(Organization $organization, ?User $actor = null): array
    {
        $organizationId = (int) $organization->getKey();

        if (! Organization::withTrashed()->whereKey($organizationId)->exists()) {
            return [];
        }

        $conferences = Conference::withTrashed()->where('organization_id', $organizationId)->get();

        /** @var list<string> $paths */
        $paths = [];

        /** @var array<string, int> $result */
        $result = DB::transaction(function () use ($organization, $organizationId, $conferences, &$paths): array {
            $counts = [];

            // The conference subtree is PurgeConference's order, called rather
            // than repeated: it is the one thing in this application that must
            // not exist twice.
            foreach ($conferences as $conference) {
                [$rows, $conferencePaths] = $this->conferences->rows($conference);

                foreach ($rows as $table => $count) {
                    $counts[$table] = ($counts[$table] ?? 0) + $count;
                }

                $paths = [...$paths, ...$conferencePaths];
            }

            // What is left is the organization's own: the tenant-level mail
            // history (the per-conference rows went with their conference),
            // the memberships, the invitations, the organization-targeted
            // short links, and the row itself.
            //
            // An empty conference list is deliberate: every conference's links
            // went with its conference above, so only the organization arm of
            // that query still has anything to find.
            $shortLinkIds = $this->ids($this->shortLinks($organization, []));

            $counts['email_logs'] = ($counts['email_logs'] ?? 0)
                + EmailLog::query()->where('organization_id', $organizationId)->delete();
            $counts['short_link_visits'] = ($counts['short_link_visits'] ?? 0)
                + ShortLinkVisit::query()->whereIn('short_link_id', $shortLinkIds)->delete();
            $counts['short_links'] = ($counts['short_links'] ?? 0)
                + ShortLink::query()->whereIn('id', $shortLinkIds)->delete();
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

        $result['private files'] = count($paths);

        // Plan 6 Task 4's own line, reachable only now that Task 1 has landed
        // App\Support\Domains\CustomDomains: the slug and the custom domain are
        // both unique and both checked withTrashed(), so both are free the
        // moment the row is gone - and the cached verified-host list has to be
        // told, or a purged tenant's host keeps resolving until the cache
        // expires.
        CustomDomains::forget();

        if ($actor instanceof User) {
            // No performedOn(): the subject is gone. Optional, because
            // cass:demo-reset runs with no actor at all.
            activity()
                ->causedBy($actor)
                ->withProperties([
                    'organization_slug' => (string) $organization->slug,
                    'organization_name' => (string) $organization->name,
                    'rows' => $result,
                ])
                ->log('organization.purged');
        }

        return $result;
    }

    /**
     * What handle() would delete, without deleting it - the count-per-table
     * preview the admin confirmation modal shows *before* anything happens.
     *
     * It is handle()'s own shape with ->count() in place of ->delete(): every
     * conference through PurgeConference::preview(), then the organization's
     * own rows, so the two cannot describe different trees.
     *
     * @return array<string, int>
     */
    public function preview(Organization $organization): array
    {
        $organizationId = (int) $organization->getKey();

        $conferences = Conference::withTrashed()->where('organization_id', $organizationId)->get();
        $conferenceIds = $this->ids(Conference::withTrashed()->where('organization_id', $organizationId));
        $submissionIds = $this->ids(Submission::withTrashed()->whereIn('conference_id', $conferenceIds));

        /** @var array<string, int> $counts */
        $counts = [];
        $files = 0;

        foreach ($conferences as $conference) {
            foreach ($this->conferences->preview($conference) as $table => $count) {
                if ($table === 'private files') {
                    $files += $count;

                    continue;
                }

                $counts[$table] = ($counts[$table] ?? 0) + $count;
            }
        }

        $shortLinkIds = $this->ids($this->shortLinks($organization, []));

        // SET, not add. The per-conference previews all run against the same
        // untouched database, so a row carrying both organization_id and
        // conference_id would be counted twice by a sum - while handle()
        // deletes it exactly once, in whichever pass reaches it first. This one
        // union count over the whole tenant is precisely the set handle()
        // removes between them.
        $counts['email_logs'] = EmailLog::query()
            ->where(function (Builder $query) use ($organizationId, $conferenceIds, $submissionIds): void {
                $query->where('organization_id', $organizationId)
                    ->orWhereIn('conference_id', $conferenceIds)
                    ->orWhereIn('submission_id', $submissionIds);
            })
            ->count();

        // Added, not set: a conference-targeted short link and an
        // organization-targeted one differ in target_type, so the two passes
        // cannot see the same row.
        $counts['short_link_visits'] = ($counts['short_link_visits'] ?? 0)
            + ShortLinkVisit::query()->whereIn('short_link_id', $shortLinkIds)->count();
        $counts['short_links'] = ($counts['short_links'] ?? 0)
            + ShortLink::query()->whereIn('id', $shortLinkIds)->count();
        $counts['organization_invitations'] = OrganizationInvitation::query()->where('organization_id', $organizationId)->count();
        $counts['organization_members'] = OrganizationMember::query()->where('organization_id', $organizationId)->count();
        $counts['organizations'] = Organization::withTrashed()->whereKey($organizationId)->count();
        $counts['private files'] = $files;

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
