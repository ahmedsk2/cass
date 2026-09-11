<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\ReviewAnswer;
use App\Models\ReviewQuestion;
use App\Models\User;
use Filament\Facades\Filament;

class ReviewQuestionPolicy
{
    public function viewAny(User $user): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Organization && $user->roleIn($tenant) !== null;
    }

    /**
     * Spec section 3: once the first review is submitted the form is locked and
     * only new questions may be appended, so `create` ignores the lock while
     * `update` and `delete` respect it. `reorder` receives no record and cannot
     * see the lock, so the relation manager enforces that with
     * `reorderable(null)`. The model enforces update/delete for non-panel paths.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ReviewQuestion $reviewQuestion): bool
    {
        return $user->roleIn($reviewQuestion->reviewForm->conference->organization) !== null;
    }

    public function update(User $user, ReviewQuestion $reviewQuestion): bool
    {
        return $this->view($user, $reviewQuestion) && ! $reviewQuestion->reviewForm->isLocked();
    }

    /**
     * Deleting is narrower than updating, and the extra rule is not the lock:
     * a draft answer is written by SaveReviewDraft, which locks nothing, so a
     * question can carry answer rows while the form is still unlocked - and
     * `review_answers.review_question_id` is `restrictOnDelete`. Without this
     * the button is live and the click is a foreign-key 500.
     * ReviewQuestion::booted() refuses the same thing for every non-panel path.
     */
    public function delete(User $user, ReviewQuestion $reviewQuestion): bool
    {
        return $this->update($user, $reviewQuestion)
            && ! ReviewAnswer::query()->where('review_question_id', $reviewQuestion->getKey())->exists();
    }

    /**
     * Filament checks a bulk action with the record-less `*Any` abilities and
     * treats a policy that does not define one as allowed. delete() here turns
     * on the form's lock, which these cannot see - the same reason `reorder`
     * is disabled in the relation manager rather than decided here - so a bulk
     * delete is refused outright instead of guessing. Review questions are not
     * soft-deletable either.
     */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return $this->viewAny($user);
    }
}
