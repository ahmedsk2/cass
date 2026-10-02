<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\OrganizationStatus;
use App\Exceptions\ConferenceNotPublishable;
use App\Models\Conference;
use App\Models\ShortLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PublishConference
{
    /**
     * Spec 5.2: publishing requires an approved organization, a submission
     * window whose deadline is still in the future, and an active review form
     * with at least one question. Every failure is a sentence the organizer
     * can act on, not a validation code.
     *
     * @return list<string> empty when the conference may be published
     */
    public function blockers(Conference $conference): array
    {
        $reasons = [];

        $organization = $conference->organization;

        if ($organization->status === OrganizationStatus::Pending) {
            $reasons[] = __('organizer.publish.errors.pending');
        }

        if ($organization->status === OrganizationStatus::Suspended) {
            $reasons[] = __('organizer.publish.errors.suspended');
        }

        if ($conference->submission_opens_at === null || $conference->submission_deadline === null) {
            $reasons[] = __('organizer.publish.errors.no_window');
        } else {
            if ($conference->submission_deadline->isPast()) {
                $reasons[] = __('organizer.publish.errors.deadline_past');
            }

            if ($conference->submission_deadline->lessThanOrEqualTo($conference->submission_opens_at)) {
                $reasons[] = __('organizer.publish.errors.deadline_order');
            }
        }

        if (($conference->reviewForm()->first()?->questions()->count() ?? 0) === 0) {
            $reasons[] = __('organizer.publish.errors.no_questions');
        }

        if ($conference->status === ConferenceStatus::Archived) {
            $reasons[] = __('organizer.publish.errors.archived');
        } elseif (! $conference->status->canTransitionTo(ConferenceStatus::Open)) {
            // mb_strtolower() and a placeholder, the shape StartReviewing and
            // MarkDecided already use for the same sentence.
            $reasons[] = __('organizer.publish.errors.wrong_status', [
                'status' => mb_strtolower($conference->status->getLabel()),
            ]);
        }

        return $reasons;
    }

    public function handle(Conference $conference, User $actor): Conference
    {
        $reasons = $this->blockers($conference);

        if ($reasons !== []) {
            throw new ConferenceNotPublishable($reasons);
        }

        return DB::transaction(function () use ($conference, $actor): Conference {
            $conference->forceFill([
                'status' => ConferenceStatus::Open,
                'published_at' => $conference->published_at ?? now(),
            ])->save();

            // Idempotent, so a republished conference keeps the code already
            // printed on posters and in emails (spec 5.7).
            ShortLink::forTarget($conference);

            activity()->performedOn($conference)->causedBy($actor)->log('conference.published');

            return $conference->refresh();
        });
    }
}
