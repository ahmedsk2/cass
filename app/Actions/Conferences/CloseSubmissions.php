<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\User;
use InvalidArgumentException;

class CloseSubmissions
{
    public function handle(Conference $conference, User $actor): Conference
    {
        if (! $conference->status->canTransitionTo(ConferenceStatus::Closed)) {
            throw new InvalidArgumentException(
                'Only a conference that is open for submissions can be closed; this one is '.strtolower($conference->status->getLabel()).'.'
            );
        }

        $conference->forceFill(['status' => ConferenceStatus::Closed])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.closed');

        return $conference->refresh();
    }
}
