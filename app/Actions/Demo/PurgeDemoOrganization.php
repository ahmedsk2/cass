<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Actions\Organizations\PurgeOrganization;
use App\Exceptions\DemoRefused;
use App\Models\Organization;
use App\Models\User;

/**
 * Everything behind `php artisan cass:demo-reset`: the general
 * PurgeOrganization, narrowed to the one organization the seeder owns, plus the
 * three throwaway reviewer accounts it created.
 *
 * The `is_demo` gate is the whole safety story. It is not fillable, no form can
 * set it, and only SeedDemo writes it - so an operator who mistypes a slug, or
 * a future organization that happens to be called "demo society", meets a
 * refusal rather than a hard delete.
 */
final class PurgeDemoOrganization
{
    public function __construct(private readonly PurgeOrganization $purge) {}

    /** The demo organization, trashed or not, or null when there is none. */
    public function organization(): ?Organization
    {
        return Organization::withTrashed()->where('slug', SeedDemo::ORGANIZATION_SLUG)->first();
    }

    /**
     * @return array<string, int> what was deleted, keyed by table
     */
    public function handle(Organization $organization): array
    {
        if ($organization->is_demo !== true) {
            throw DemoRefused::because(
                "[{$organization->slug}] is not flagged is_demo, so it is not demonstration data and this command will not hard-delete it."
            );
        }

        // Read before anything is deleted. After the purge every member of this
        // organization has no memberships left, which is exactly what
        // removeOrphanReviewers() below tests for - so without this list the
        // owner would qualify as an orphan if they ever used one of the three
        // demo reviewer addresses.
        /** @var list<int> $memberIds */
        $memberIds = $organization->members()
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $counts = $this->purge->handle($organization);

        $counts['demo reviewer users'] = $this->removeOrphanReviewers($memberIds);

        return $counts;
    }

    /**
     * The three `demo.reviewerN@example.com` accounts, and only where they are
     * left with nothing: no organization, no reviewership, no review and no
     * assignment anywhere on the platform. A reviewer the owner also invited to
     * a real conference keeps their account and their password.
     *
     * @param  list<int>  $keep  user ids that were members of the organization
     */
    private function removeOrphanReviewers(array $keep): int
    {
        $removed = 0;

        foreach (SeedDemo::reviewerEmails() as $email) {
            $user = User::query()->where('email', $email)->first();

            if (! $user instanceof User) {
                continue;
            }

            if (in_array((int) $user->getKey(), $keep, true)) {
                continue;
            }

            // Belt to the braces above: a platform administrator is never
            // deleted by a demo command, whatever address they signed up with.
            if ($user->is_platform_admin === true) {
                continue;
            }

            if ($user->organizations()->exists()
                || $user->conferenceReviewerships()->exists()
                || $user->reviews()->exists()
                || $user->reviewAssignments()->exists()) {
                continue;
            }

            $user->delete();

            $removed++;
        }

        return $removed;
    }
}
