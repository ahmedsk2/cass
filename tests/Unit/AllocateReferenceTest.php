<?php

declare(strict_types=1);

use App\Actions\Submissions\AllocateReference;
use App\Models\Conference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('hands out one number per call, zero padded to three digits', function () {
    $conference = Conference::factory()->create(['reference_prefix' => 'GPCC26']);
    $allocate = app(AllocateReference::class);

    // Two calls inside one transaction is the sequential stand-in for two
    // concurrent submits: the second must see the first one's write, which is
    // exactly what the lockForUpdate() re-read guarantees on MySQL. SQLite
    // ignores the lock clause and serialises writes anyway, so this asserts the
    // re-read, not the lock - the MySQL run in Task 14 asserts the lock.
    [$first, $second] = DB::transaction(fn (): array => [
        $allocate->handle($conference),
        $allocate->handle($conference),
    ]);

    expect($first)->toBe('GPCC26-001')
        ->and($second)->toBe('GPCC26-002')
        ->and($conference->refresh()->submission_counter)->toBe(2);
});

it('does not reuse a number after a stale in-memory copy', function () {
    // The bug this prevents: SubmitAbstract holds a Conference loaded by the
    // route binder minutes earlier. Allocating from that stale instance must
    // still read the counter from the database.
    $conference = Conference::factory()->create(['reference_prefix' => 'KSAU30']);
    $stale = Conference::query()->whereKey($conference->getKey())->firstOrFail();

    app(AllocateReference::class)->handle($conference);
    $second = app(AllocateReference::class)->handle($stale);

    expect($second)->toBe('KSAU30-002');
});

it('grows past 999 without changing shape', function () {
    $conference = Conference::factory()->create(['reference_prefix' => 'GPCC26']);
    $conference->forceFill(['submission_counter' => 999])->save();

    expect(app(AllocateReference::class)->handle($conference))->toBe('GPCC26-1000');
});

it('derives a prefix for a conference that has none', function () {
    $conference = Conference::factory()->create([
        'name' => 'Winter School of Critical Care',
        'starts_at' => '2027-01-10',
    ]);
    $conference->forceFill(['reference_prefix' => null])->save();

    expect(app(AllocateReference::class)->handle($conference->refresh()))->toBe('WSCC27-001');
});

it('keeps sequences separate per conference', function () {
    $alpha = Conference::factory()->create(['reference_prefix' => 'AAA26']);
    $beta = Conference::factory()->create(['reference_prefix' => 'BBB26']);
    $allocate = app(AllocateReference::class);

    expect($allocate->handle($alpha))->toBe('AAA26-001')
        ->and($allocate->handle($beta))->toBe('BBB26-001')
        ->and($allocate->handle($alpha))->toBe('AAA26-002');
});
