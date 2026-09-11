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
        // Not canTransitionTo(Closed): the status graph also allows
        // Reviewing -> Closed (plan 4 rolls a review back deliberately), so
        // that guard would let this action quietly undo a move to review.
        // Closing submissions is the Open -> Closed step and nothing else.
        if ($conference->status !== ConferenceStatus::Open) {
            throw new InvalidArgumentException(
                'Only a conference that is open for submissions can be closed; this one is '.strtolower($conference->status->getLabel()).'.'
            );
        }

        $conference->forceFill(['status' => ConferenceStatus::Closed])->save();

        activity()->performedOn($conference)->causedBy($actor)->log('conference.closed');

        return $conference->refresh();
    }
}
