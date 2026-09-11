<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class CreateConference
{
    public function __construct(private readonly CreateDefaultReviewForm $createDefaultReviewForm) {}

    /**
     * The tenant, the slug and the starting status are set here rather than in
     * a model event so the result does not depend on the order in which
     * Filament's tenancy `creating` observer and the model's own `booted()`
     * listener happen to be registered.
     *
     * `slug` is optional (spec 5.2 lets the organizer choose the public
     * address) and is not fillable, so it is taken out of $data before fill()
     * - Model::preventSilentlyDiscardingAttributes() is on outside production
     * and would throw on it.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Organization $organization, array $data): Conference
    {
        return DB::transaction(function () use ($organization, $data): Conference {
            $slug = is_string($data['slug'] ?? null) && trim($data['slug']) !== '' ? $data['slug'] : null;
            unset($data['slug']);

            $conference = new Conference;
            $conference->fill($data);
            $conference->organization()->associate($organization);
            // A chosen slug goes through the same uniqueSlug() as a derived
            // one: the caller is not always the panel form, so "GPCC 2026"
            // has to become gpcc-2026, and a slug that is already taken in
            // this organization (a trashed conference included) has to be
            // suffixed rather than hit the unique index.
            $conference->slug = Conference::uniqueSlug($organization->id, $slug ?? (string) ($data['name'] ?? ''));
            // Eloquent does not read column defaults back after an INSERT, so
            // an unset status would be null on the instance this returns -
            // and $conference->status->canTransitionTo() in the publish gate
            // would then be a call on null.
            $conference->status = ConferenceStatus::Draft;
            $conference->save();

            $this->createDefaultReviewForm->handle($conference);

            // Loads the remaining column defaults (review_mode, blind_review,
            // reviewers_per_submission, ...) for the keys $data omitted.
            return $conference->refresh();
        });
    }
}
