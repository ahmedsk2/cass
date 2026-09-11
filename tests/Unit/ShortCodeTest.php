<?php

declare(strict_types=1);

use App\Models\Conference;
use App\Models\ShortLink;
use App\Support\ShortCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates eight characters from an unambiguous uppercase alphabet', function () {
    foreach (range(1, 200) as $ignored) {
        $code = ShortCode::generate();

        expect($code)->toHaveLength(8)
            ->and($code)->toMatch('/^['.ShortCode::ALPHABET.']{8}$/');
    }
});

it('excludes every character a person could misread', function () {
    expect(ShortCode::ALPHABET)->not->toContain('0')
        ->and(ShortCode::ALPHABET)->not->toContain('1')
        ->and(ShortCode::ALPHABET)->not->toContain('I')
        ->and(ShortCode::ALPHABET)->not->toContain('L')
        ->and(ShortCode::ALPHABET)->not->toContain('O')
        ->and(ShortCode::ALPHABET)->not->toContain('U')
        ->and(strlen(ShortCode::ALPHABET))->toBe(30);
});

it('does not repeat itself over a large sample', function () {
    $codes = collect(range(1, 500))->map(fn (): string => ShortCode::generate());

    expect($codes->unique())->toHaveCount(500);
});

it('creates one short link per conference and reuses it', function () {
    $conference = Conference::factory()->create();

    $first = ShortLink::forTarget($conference);
    $second = ShortLink::forTarget($conference);

    expect($first->is($second))->toBeTrue()
        ->and(ShortLink::count())->toBe(1)
        ->and($first->target->is($conference))->toBeTrue()
        ->and($conference->fresh()?->shortLink?->is($first))->toBeTrue()
        ->and($first->url())->toBe(url('/q/'.$first->code));
});

it('retries until the generated code is free', function () {
    // 30^8 is about 6.6e11 codes, so drawing a taken one at random never
    // happens: without a seam this test would pass even if forTarget() had no
    // uniqueness loop at all.
    ShortLink::factory()->create(['code' => 'ABCDEFGH']);
    $codes = ['ABCDEFGH', 'ABCDEFGH', 'ZYXWVTS2'];
    ShortLink::generateCodesUsing(function () use (&$codes): string {
        return (string) array_shift($codes);
    });

    try {
        expect(ShortLink::forTarget(Conference::factory()->create())->code)->toBe('ZYXWVTS2')
            ->and(ShortLink::count())->toBe(2);
    } finally {
        ShortLink::generateCodesUsing(null);
    }
});

it('zero fills the daily visit counts for the last n days', function () {
    // Freeze the clock: the test writes visits with now(), the model reads
    // CarbonImmutable::now(), and the assertions build their keys with now()
    // again - a run that crossed midnight would read an empty day.
    $this->travelTo(now()->setTime(12, 0));

    $link = ShortLink::factory()->create();
    $link->visits()->create(['visited_at' => now()]);
    $link->visits()->create(['visited_at' => now()]);
    $link->visits()->create(['visited_at' => now()->subDays(2)]);
    $link->visits()->create(['visited_at' => now()->subDays(60)]);

    $counts = $link->dailyVisitCounts(30);

    expect($counts)->toHaveCount(30)
        ->and($counts[now()->toDateString()])->toBe(2)
        ->and($counts[now()->subDays(2)->toDateString()])->toBe(1)
        ->and($counts[now()->subDay()->toDateString()])->toBe(0)
        ->and(array_sum($counts))->toBe(3);
});

it('buckets the daily counts by the day in the requested timezone', function () {
    // 22:30 UTC is already the next day in Riyadh (+03), which is the timezone
    // the sharing page labels the chart with.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 22:30:00', 'UTC'));

    $link = ShortLink::factory()->create();
    $link->visits()->create(['visited_at' => now()]);

    expect($link->dailyVisitCounts(30)['2026-11-30'])->toBe(1)
        ->and($link->dailyVisitCounts(30, 'Asia/Riyadh')['2026-12-01'])->toBe(1);
});
