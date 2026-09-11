<?php

declare(strict_types=1);

namespace App\Filament\Reviewer\Pages;

use App\Models\Conference;
use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Spec 5.4 step 3: "Reviewer panel shows conferences, progress, and a queue."
 *
 * This task builds the conference list. Task 6 adds the link into each queue
 * and Task 11 adds the progress numbers, each in the task that makes them true.
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.reviewer.pages.dashboard';

    public function getTitle(): string
    {
        return __('reviewer.dashboard.title');
    }

    /**
     * Every conference this person is an active reviewer of, in whatever state.
     * A conference that has not reached Reviewing yet is still listed, with a
     * sentence saying so - otherwise a reviewer who accepted an invitation last
     * week sees an empty page and writes to the organizer.
     *
     * The `whereHas` closure parameter is typed because Larastan level 6 runs
     * MissingClosureParameterTypehintRule: "Anonymous function has parameter
     * $query with no type specified" is an error, not a warning, and there is
     * no untyped closure parameter anywhere in app/ to copy. The bare
     * `Builder` with no type variables is the house style and passes - see
     * app/Filament/Organizer/Resources/Submissions/Tables/SubmissionsTable.php:87.
     *
     * @return Collection<int, Conference>
     */
    public function getConferences(): Collection
    {
        $user = $this->reviewer();

        if ($user === null) {
            /** @var Collection<int, Conference> $empty */
            $empty = Conference::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        /** @var Collection<int, Conference> $conferences */
        $conferences = Conference::query()
            ->with('organization')
            ->whereHas('activeReviewers', fn (Builder $query): Builder => $query->where('user_id', $user->getKey()))
            ->orderBy('review_deadline')
            ->orderBy('name')
            ->get();

        return $conferences;
    }

    public function reviewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
