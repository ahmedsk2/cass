<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Enums\ReviewMode;
use App\Exceptions\ConferenceUpdateRefused;
use App\Models\Conference;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * The one writer of the organizer's conference edit form: Filament's own save plus the
 * review-settings lock, and the actor as causer of the model's deadline activity entry.
 */
class UpdateConference
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<string> empty when the edit may be saved
     */
    public function blockers(Conference $conference, array $data, User $actor): array
    {
        $organization = $conference->organization;

        if ($organization === null || $actor->roleIn($organization) === null) {
            return [__('organizer.update.errors.not_allowed')];
        }

        if (! self::reviewSettingsLocked($conference)) {
            return [];
        }

        $reasons = [];
        $mode = $data['review_mode'] ?? null;
        $mode = $mode instanceof ReviewMode ? $mode : (is_string($mode) ? ReviewMode::tryFrom($mode) : null);

        if (array_key_exists('review_mode', $data) && $mode !== $conference->review_mode) {
            $reasons[] = __('organizer.update.errors.review_mode_locked');
        }

        if (array_key_exists('blind_review', $data) && (bool) $data['blind_review'] !== $conference->blind_review) {
            $reasons[] = __('organizer.update.errors.blind_review_locked');
        }

        return $reasons;
    }

    /** @param  array<string, mixed>  $data */
    public function handle(Conference $conference, array $data, User $actor): Conference
    {
        $reasons = $this->blockers($conference, $data, $actor);

        if ($reasons !== []) {
            throw new ConferenceUpdateRefused($reasons);
        }

        // As Filament did: $fillable keeps `status` out, and a stray key throws outside production.
        $conference->fill($data);

        // Not the auth guard's user, who is the actor on the panel and nobody elsewhere.
        app(CauserResolver::class)->withCauser($actor, fn (): bool => $conference->save());

        return $conference;
    }

    /**
     * Both settings change what a written review meant, so they lock from
     * `reviewing` on and while any review exists (Reviewing -> Closed is allowed).
     */
    public static function reviewSettingsLocked(Conference $conference): bool
    {
        if (in_array($conference->status, [ConferenceStatus::Reviewing, ConferenceStatus::Decided, ConferenceStatus::Archived], true)) {
            return true;
        }

        return Review::query()
            ->whereIn('submission_id', Submission::withTrashed()->select('id')->where('conference_id', $conference->getKey()))
            ->exists();
    }
}
