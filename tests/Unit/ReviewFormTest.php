<?php

declare(strict_types=1);

use App\Enums\CustomFieldType;
use App\Enums\ReviewQuestionType;
use App\Exceptions\ReviewFormLocked;
use App\Models\Conference;
use App\Models\CustomField;
use App\Models\ReviewForm;
use App\Models\ReviewQuestion;
use App\Models\Track;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hangs tracks and custom fields off a conference in sort order', function () {
    $conference = Conference::factory()->create();

    Track::factory()->for($conference)->create(['name' => 'Neonatology', 'sort' => 2]);
    Track::factory()->for($conference)->create(['name' => 'Cardiology', 'sort' => 1]);

    expect($conference->tracks()->pluck('name')->all())->toBe(['Cardiology', 'Neonatology']);

    $field = CustomField::factory()->for($conference)->create([
        'label' => 'Funding source',
        'type' => CustomFieldType::Select,
        'options' => ['None', 'Institutional', 'Industry'],
        'required' => true,
    ]);

    // Read the row back: the in-memory attributes the factory just built would
    // satisfy the assertions below even with every cast removed (the driver
    // binds a BackedEnum as its value on insert), so only a database round trip
    // actually exercises CustomField::casts().
    $field->refresh();

    expect($field->key)->toBe('funding_source')
        ->and($field->type)->toBe(CustomFieldType::Select)
        ->and($field->options)->toBe(['None', 'Institutional', 'Industry'])
        ->and($conference->customFields()->count())->toBe(1);
});

it('derives a unique custom field key per conference', function () {
    $conference = Conference::factory()->create();

    $first = CustomField::factory()->for($conference)->create(['label' => 'IRB approval']);
    $second = CustomField::factory()->for($conference)->create(['label' => 'IRB approval']);
    $elsewhere = CustomField::factory()->create(['label' => 'IRB approval']);

    expect($first->key)->toBe('irb_approval')
        ->and($second->key)->toBe('irb_approval_2')
        ->and($elsewhere->key)->toBe('irb_approval');
});

it('exposes one active review form and its questions from the conference', function () {
    $conference = Conference::factory()->create();
    $form = ReviewForm::factory()->for($conference)->create();
    ReviewQuestion::factory()->for($form)->create(['prompt' => 'Second', 'sort' => 2]);
    ReviewQuestion::factory()->for($form)->create(['prompt' => 'First', 'sort' => 1]);

    expect($conference->reviewForm?->is($form))->toBeTrue()
        ->and($conference->reviewForm?->questions()->pluck('prompt')->all())->toBe(['First', 'Second'])
        ->and($conference->reviewQuestions()->count())->toBe(2)
        ->and($form->isLocked())->toBeFalse();
});

it('ignores an inactive review form when resolving the active one', function () {
    $conference = Conference::factory()->create();
    ReviewForm::factory()->for($conference)->create(['name' => 'Old form', 'is_active' => false]);
    $active = ReviewForm::factory()->for($conference)->create(['name' => 'Current form']);

    expect($conference->reviewForm?->is($active))->toBeTrue()
        ->and($conference->reviewForms()->count())->toBe(2);
});

it('stores a likert question with its scale and weight', function () {
    $question = ReviewQuestion::factory()->create([
        'prompt' => 'Originality and Innovation: How original and innovative is the research presented in the abstract?',
        'type' => ReviewQuestionType::Likert,
        'scale_min' => 1,
        'scale_max' => 5,
        'weight' => '1.50',
    ]);

    // Assert on the stored row, never on the factory's in-memory model: without
    // the `type` cast a row loaded from the database hands back the string
    // "likert" and isScored() fatals, yet every in-memory assertion still
    // passes. SQLite also gives a decimal column NUMERIC affinity and hands
    // back int(1) / float(1.5), so only the `decimal:2` cast makes the weight a
    // two-decimal string on both drivers.
    $stored = $question->fresh();

    expect($stored?->type)->toBe(ReviewQuestionType::Likert)
        ->and($stored?->scale_min)->toBe(1)
        ->and($stored?->scale_max)->toBe(5)
        ->and($stored?->weight)->toBe('1.50')
        ->and($stored?->isScored())->toBeTrue();
});

it('locks existing questions once the form is locked but still allows new ones', function () {
    $form = ReviewForm::factory()->locked()->create();
    $existing = ReviewQuestion::factory()->for($form)->create(['prompt' => 'Originality']);

    expect($form->isLocked())->toBeTrue();

    expect(fn () => $existing->update(['prompt' => 'Changed']))->toThrow(ReviewFormLocked::class);
    expect(fn () => $existing->delete())->toThrow(ReviewFormLocked::class);

    // Spec section 3: after the lock, questions may still be appended.
    $appended = ReviewQuestion::factory()->for($form)->create(['prompt' => 'Added later', 'sort' => 99]);
    expect($appended->exists)->toBeTrue()
        ->and($form->questions()->count())->toBe(2);
});

it('appends a new row to the end of its parent instead of sorting it first', function () {
    $conference = Conference::factory()->create();
    Track::factory()->for($conference)->create(['name' => 'Cardiology', 'sort' => 1]);
    Track::factory()->for($conference)->create(['name' => 'Neurology', 'sort' => 2]);
    CustomField::factory()->for($conference)->create(['label' => 'Funding', 'sort' => 4]);
    $form = ReviewForm::factory()->for($conference)->create();
    ReviewQuestion::factory()->for($form)->create(['prompt' => 'First', 'sort' => 1]);

    // Filament's CreateAction never fills the reorder column, so a row created
    // through a relation manager would otherwise take the DB default 0 and sort
    // above everything that already exists.
    $track = new Track(['name' => 'Respiratory']);
    $track->conference()->associate($conference);
    $track->save();

    $field = new CustomField(['label' => 'IRB approval', 'type' => CustomFieldType::Text]);
    $field->conference()->associate($conference);
    $field->save();

    $question = new ReviewQuestion(['prompt' => 'Added later', 'type' => ReviewQuestionType::Text]);
    $question->reviewForm()->associate($form);
    $question->save();

    expect($track->sort)->toBe(3)
        ->and($field->sort)->toBe(5)
        ->and($question->sort)->toBe(2)
        ->and($conference->tracks()->pluck('name')->last())->toBe('Respiratory')
        ->and($form->questions()->pluck('prompt')->last())->toBe('Added later');
});

it('leaves an unlocked form fully editable', function () {
    $form = ReviewForm::factory()->create();
    $question = ReviewQuestion::factory()->for($form)->create(['prompt' => 'Clarity', 'sort' => 1]);

    $question->update(['sort' => 4, 'prompt' => 'Clarity of presentation']);
    expect($question->fresh()?->sort)->toBe(4);

    $question->delete();
    expect($form->questions()->count())->toBe(0);
});

it('refuses to hard delete a conference that still has children', function () {
    // Spec section 3: deleting a conference is soft-delete only, and the
    // platform-admin hard purge cascades in application code. The database
    // must therefore RESTRICT rather than quietly removing the tree (which
    // would also skip the review-question lock hooks).
    $conference = Conference::factory()->create();
    Track::factory()->for($conference)->create();

    expect(fn () => $conference->forceDelete())->toThrow(QueryException::class)
        ->and(Track::count())->toBe(1)
        ->and(Conference::withTrashed()->count())->toBe(1);
});
