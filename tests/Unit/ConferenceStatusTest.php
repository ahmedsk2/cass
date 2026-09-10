<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;

it('labels and colours every status', function () {
    foreach (ConferenceStatus::cases() as $status) {
        expect($status->getLabel())->toBeString()->not->toBeEmpty()
            ->and($status->getColor())->toBeString()->not->toBeEmpty();
    }

    expect(ConferenceStatus::Open->getLabel())->toBe('Open for submissions')
        ->and(ConferenceStatus::Draft->getColor())->toBe('gray');
});

it('allows only the transitions plan 2 and later plans implement', function () {
    expect(ConferenceStatus::Draft->canTransitionTo(ConferenceStatus::Open))->toBeTrue()
        ->and(ConferenceStatus::Closed->canTransitionTo(ConferenceStatus::Open))->toBeTrue()
        ->and(ConferenceStatus::Open->canTransitionTo(ConferenceStatus::Closed))->toBeTrue()
        ->and(ConferenceStatus::Closed->canTransitionTo(ConferenceStatus::Reviewing))->toBeTrue()
        ->and(ConferenceStatus::Reviewing->canTransitionTo(ConferenceStatus::Decided))->toBeTrue()
        ->and(ConferenceStatus::Draft->canTransitionTo(ConferenceStatus::Archived))->toBeTrue()
        ->and(ConferenceStatus::Archived->canTransitionTo(ConferenceStatus::Open))->toBeFalse()
        ->and(ConferenceStatus::Draft->canTransitionTo(ConferenceStatus::Decided))->toBeFalse()
        ->and(ConferenceStatus::Open->canTransitionTo(ConferenceStatus::Open))->toBeFalse();
});

it('knows which statuses are public and which still take submissions', function () {
    expect(ConferenceStatus::Draft->isPublic())->toBeFalse()
        ->and(ConferenceStatus::Archived->isPublic())->toBeFalse()
        ->and(ConferenceStatus::Open->isPublic())->toBeTrue()
        ->and(ConferenceStatus::Decided->isPublic())->toBeTrue()
        ->and(ConferenceStatus::Open->acceptsSubmissions())->toBeTrue()
        ->and(ConferenceStatus::Closed->acceptsSubmissions())->toBeFalse();
});
