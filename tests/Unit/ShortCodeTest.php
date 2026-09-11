<?php

declare(strict_types=1);

use App\Models\Conference;
use App\Models\ShortLink;
use App\Support\ShortCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

it('refuses a second short link for the same target at the database level', function () {
    // The idempotence promise in forTarget() is a select-then-insert, so the
    // only thing that can keep it true when two publishes race is the index.
    $conference = Conference::factory()->create();
    ShortLink::forTarget($conference);

    expect(fn () => DB::table('short_links')->insert([
        'code' => 'SECONDLK',
        'target_type' => $conference->getMorphClass(),
        'target_id' => $conference->getKey(),
        'clicks' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('returns the row a concurrent publish inserted for the same target', function () {
    // The code generator runs after forTarget() has looked for an existing
    // row, so this closure is exactly the window two publishes race through.
    $conference = Conference::factory()->create();

    ShortLink::generateCodesUsing(function () use ($conference): string {
        DB::table('short_links')->insertOrIgnore([
            'code' => 'RACEWON2',
            'target_type' => $conference->getMorphClass(),
            'target_id' => $conference->getKey(),
            'clicks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'RACELOST';
    });

    try {
        $link = ShortLink::forTarget($conference);
    } finally {
        ShortLink::generateCodesUsing(null);
    }

    expect($link->code)->toBe('RACEWON2')
        ->and(ShortLink::count())->toBe(1);
});

it('streams the visit rows instead of hydrating the whole window', function () {
    // /q is unauthenticated and writes one row per counted scan, so the number
    // of rows in the window is decided by whoever scans, not by the organizer.
    $link = ShortLink::factory()->create();

    foreach (range(1, 5) as $ignored) {
        $link->visits()->create(['visited_at' => now()]);
    }

    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'short_link_visits')) {
            $queries[] = $query->sql;
        }
    });

    $link->dailyVisitCounts(30);

    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toContain('order by')
        ->and($queries[0])->toContain('limit');
});
