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
            $reasons[] = 'Your organization is still waiting for platform approval. You can publish as soon as it is approved.';
        }

        if ($organization->status === OrganizationStatus::Suspended) {
            $reasons[] = 'This organization is suspended, so its conferences cannot be published.';
        }

        if ($conference->submission_opens_at === null || $conference->submission_deadline === null) {
            $reasons[] = 'Set both a submission opening date and a submission deadline.';
        } else {
            if ($conference->submission_deadline->isPast()) {
                $reasons[] = 'The submission deadline is in the past. Choose a future date and time.';
            }

            if ($conference->submission_deadline->lessThanOrEqualTo($conference->submission_opens_at)) {
                $reasons[] = 'The submission deadline must come after the submission opening date.';
            }
        }

        if (($conference->reviewForm()->first()?->questions()->count() ?? 0) === 0) {
            $reasons[] = 'The review form has no questions yet. Add at least one before publishing.';
        }

        if ($conference->status === ConferenceStatus::Archived) {
            $reasons[] = 'An archived conference cannot be published again.';
        } elseif (! $conference->status->canTransitionTo(ConferenceStatus::Open)) {
            $reasons[] = 'A conference that is '.strtolower($conference->status->getLabel()).' cannot be opened for submissions.';
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
