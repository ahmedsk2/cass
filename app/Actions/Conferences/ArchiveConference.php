<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\User;
use InvalidArgumentException;

class ArchiveConference
{
    /**
     * Archiving takes the conference off the public site without deleting
     * anything: submissions, reviews and decisions stay readable in the panel.
     * It is one-way; the status graph has no edge back out of Archived.
     */
    public function handle(Conference $conference, User $actor): Conference
    {
        if (! $conference->status->canTransitionTo(ConferenceStatus::Archived)) {
            throw new InvalidArgumentException('This conference is already archived.');
        }

        $conference->forceFill(['status' => ConferenceStatus::Archived])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.archived');

        return $conference->refresh();
    }
}
