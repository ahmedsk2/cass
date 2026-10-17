<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    $this->conference = Conference::factory()->for($this->organization)->published()->create();
    $this->manager = fn (string $class) => livewire($class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class]);
});

it('keeps a track an abstract is filed under, a withdrawn or trashed abstract included, and says why', function () {
    [$filed, $filedTrashed, $free] = Track::factory()->for($this->conference)->count(3)->create()->all();
    Submission::factory()->for($this->conference)->withdrawn()->create(['track_id' => $filed->id]);
    Submission::factory()->for($this->conference)->create(['track_id' => $filedTrashed->id])->delete();

    expect(Gate::inspect('delete', $filed)->message())->toBe(__('organizer.tracks.in_use'))
        ->and(Gate::inspect('delete', $filedTrashed)->message())->toBe(__('organizer.tracks.in_use'))
        ->and(Gate::allows('delete', $free))->toBeTrue();
    ($this->manager)(TracksRelationManager::class)
        ->assertTableActionDisabled('delete', $filed)->assertTableActionEnabled('delete', $free)
        ->callTableAction('delete', $filed)->callTableAction('delete', $free);
    expect($filed->fresh())->not->toBeNull()->and($free->fresh())->toBeNull();
});

it('keeps a custom field an abstract of this conference answered, and lets an empty or null entry go', function () {
    $answered = CustomField::factory()->for($this->conference)->create(['label' => 'First time presenting']);
    $blank = CustomField::factory()->for($this->conference)->create(['label' => 'Dietary needs']);
    $nulled = CustomField::factory()->for($this->conference)->create(['label' => 'Second language']);
    $trashedOnly = CustomField::factory()->for($this->conference)->create(['label' => 'Ethics approval']);
    // An unticked checkbox is an answer; an emptied text box and a null are not.
    Submission::factory()->for($this->conference)
        ->create(['custom_field_values' => ['first_time_presenting' => false, 'dietary_needs' => '', 'second_language' => null]]);
    // The same key answered in a sibling conference does not count here.
    Submission::factory()->for(Conference::factory()->for($this->organization)->published())
        ->create(['custom_field_values' => ['dietary_needs' => 'Vegetarian']]);
    // Answered only by an abstract in the bin: a restore would bring the answer back.
    Submission::factory()->for($this->conference)->create(['custom_field_values' => ['ethics_approval' => 'Granted']])->delete();

    expect(Gate::inspect('delete', $answered)->message())->toBe(__('organizer.custom_fields.in_use'))
        ->and(Gate::allows('delete', $blank))->toBeTrue()
        ->and(Gate::allows('delete', $nulled))->toBeTrue()
        ->and(Gate::inspect('delete', $trashedOnly)->message())->toBe(__('organizer.custom_fields.in_use'));
    ($this->manager)(CustomFieldsRelationManager::class)
        ->assertTableActionDisabled('delete', $answered)
        ->callTableAction('delete', $answered)->callTableAction('delete', $blank);
    expect($answered->fresh())->not->toBeNull()->and($blank->fresh())->toBeNull();
});

it('refuses another organization\'s referenced track and field without a reason', function () {
    [$theirTrack, $theirField] = withoutTenant(function (): array {
        $conference = Conference::factory()->published()->create();
        $track = Track::factory()->for($conference)->create();
        $field = CustomField::factory()->for($conference)->create(['label' => 'Funding source']);
        Submission::factory()->for($conference)->create(['track_id' => $track->id, 'custom_field_values' => ['funding_source' => 'None']]);

        return [$track, $field];
    });

    // Membership is asked first, so the refusal says nothing about whether the row is in use.
    foreach ([$theirTrack, $theirField] as $row) {
        expect(Gate::inspect('delete', $row)->denied())->toBeTrue()->and(Gate::inspect('delete', $row)->message())->toBeNull();
    }
});
