<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Enums\OrganizationRole;
use App\Filament\Organizer\Resources\Conferences\Pages\EditConference;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\ReviewQuestionsRelationManager;
use App\Filament\Organizer\Resources\Conferences\RelationManagers\TracksRelationManager;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->organization = Organization::factory()->approved()->create();
    $this->user = User::factory()->create();
    $this->organization->addMember($this->user, OrganizationRole::Owner);
    actingAs($this->user);
    bootOrganizerPanel($this->organization);
    $this->conference = Conference::factory()->for($this->organization)->create();
});

it('lists, creates, edits and reorders tracks', function () {
    $first = Track::factory()->for($this->conference)->create(['name' => 'Cardiology', 'sort' => 1]);
    $second = Track::factory()->for($this->conference)->create(['name' => 'Neurology', 'sort' => 2]);

    livewire(TracksRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->assertCanSeeTableRecords([$first, $second])
        ->callTableAction('create', data: ['name' => 'Respiratory', 'description' => 'Airways and ventilation'])
        ->assertHasNoTableActionErrors()
        ->callTableAction('edit', $first, data: ['name' => 'Cardiology and haemodynamics'])
        ->assertHasNoTableActionErrors()
        ->call('reorderTable', [$second->getKey(), $first->getKey()]);

    expect($this->conference->tracks()->pluck('name')->all())
        ->toContain('Respiratory')
        ->and($first->fresh()?->name)->toBe('Cardiology and haemodynamics')
        ->and($this->conference->tracks()->first()?->is($second))->toBeTrue();
});

it('deletes a track', function () {
    $track = Track::factory()->for($this->conference)->create();

    livewire(TracksRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])->callTableAction('delete', $track);

    expect(Track::count())->toBe(0);
});

it('never shows another organization tracks', function () {
    $theirConference = withoutTenant(fn () => Conference::factory()->create());
    $theirTrack = withoutTenant(fn () => Track::factory()->for($theirConference)->create());
    $mine = Track::factory()->for($this->conference)->create();

    livewire(TracksRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirTrack]);
});

it('refuses every relation manager mounted on a conference of another organization', function () {
    // Mounting the Livewire component directly bypasses the resource page's
    // tenant-scoped route binding, so canViewForRecord() has to check the
    // owner record itself - the child policies only ask whether the user
    // belongs to the *current* tenant. Filament aborts before mount when it
    // returns false, which Livewire::test() cannot observe, so assert the hook
    // directly on all three managers.
    $theirConference = withoutTenant(fn () => Conference::factory()->create());

    foreach ([TracksRelationManager::class, CustomFieldsRelationManager::class, ReviewQuestionsRelationManager::class] as $manager) {
        expect($manager::canViewForRecord($theirConference, EditConference::class))->toBeFalse()
            ->and($manager::canViewForRecord($this->conference, EditConference::class))->toBeTrue();
    }
});

it('cannot act on another organization tracks or fields from a mounted manager', function () {
    // The listing tests below can only ever pass, because the relationship
    // query is already scoped to this conference. The real surface is a
    // Livewire payload carrying a foreign record key, or a reorder over
    // foreign ids: both must resolve through the relationship and find
    // nothing.
    [$theirConference, $theirTrack, $theirField] = withoutTenant(function (): array {
        $conference = Conference::factory()->create();

        return [
            $conference,
            Track::factory()->for($conference)->create(['name' => 'Theirs', 'sort' => 7]),
            CustomField::factory()->for($conference)->create(['label' => 'Theirs']),
        ];
    });
    $mine = Track::factory()->for($this->conference)->create();

    livewire(TracksRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->mountTableAction('delete', $theirTrack)
        ->assertActionNotMounted()
        ->call('reorderTable', [$theirTrack->getKey(), $mine->getKey()]);

    livewire(CustomFieldsRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->mountTableAction('edit', $theirField)
        ->assertActionNotMounted();

    expect($theirTrack->fresh()?->sort)->toBe(7)
        ->and($theirTrack->fresh()?->name)->toBe('Theirs')
        ->and($theirField->fresh()?->label)->toBe('Theirs')
        ->and($theirConference->tracks()->count())->toBe(1);
});

it('never shows another organization custom fields', function () {
    $theirConference = withoutTenant(fn () => Conference::factory()->create());
    $theirField = withoutTenant(fn () => CustomField::factory()->for($theirConference)->create());
    $mine = CustomField::factory()->for($this->conference)->create();

    livewire(CustomFieldsRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirField]);
});

it('keeps a sibling conference of the same organization out of each manager', function () {
    // Spec section 9 asks for cross-*conference* isolation as well as
    // cross-tenant: the tenancy scope does nothing here, only the relationship
    // does.
    $sibling = Conference::factory()->for($this->organization)->create();
    $siblingTrack = Track::factory()->for($sibling)->create();
    $siblingField = CustomField::factory()->for($sibling)->create();

    livewire(TracksRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->assertCanNotSeeTableRecords([$siblingTrack]);

    livewire(CustomFieldsRelationManager::class, ['ownerRecord' => $this->conference, 'pageClass' => EditConference::class])
        ->assertCanNotSeeTableRecords([$siblingField]);
});

it('creates a custom field and derives its storage key', function () {
    livewire(CustomFieldsRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])
        ->callTableAction('create', data: [
            'label' => 'Funding source',
            'type' => CustomFieldType::Select->value,
            'options' => ['None', 'Institutional', 'Industry'],
            'required' => true,
        ])
        ->assertHasNoTableActionErrors();

    $field = CustomField::query()->firstOrFail();

    expect($field->key)->toBe('funding_source')
        ->and($field->conference_id)->toBe($this->conference->id)
        ->and($field->options)->toBe(['None', 'Institutional', 'Industry'])
        ->and($field->required)->toBeTrue();
});

it('keeps the custom field key fixed when the label changes', function () {
    $field = CustomField::factory()->for($this->conference)->create(['label' => 'Funding source']);

    livewire(CustomFieldsRelationManager::class, [
        'ownerRecord' => $this->conference,
        'pageClass' => EditConference::class,
    ])->callTableAction('edit', $field, data: ['label' => 'Source of funding']);

    $field->refresh();
    expect($field->label)->toBe('Source of funding')
        ->and($field->key)->toBe('funding_source');
});
