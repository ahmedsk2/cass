<?php

declare(strict_types=1);

use App\Enums\ReminderThreshold;
use App\Models\Conference;
use App\Support\Reviews\ReminderSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 23:59 Riyadh on 3 November 2026 is 20:59 UTC. Every expectation below is
    // written in the conference's own timezone, which is the whole point.
    $this->conference = Conference::factory()->create([
        'timezone' => 'Asia/Riyadh',
        'review_deadline' => Carbon::parse('2026-11-03 20:59:00', 'UTC'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('counts the days left in the conference timezone', function (string $nowUtc, int $expected) {
    Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));

    expect(ReminderSchedule::daysLeft($this->conference))->toBe($expected);
})->with([
    // 08:00 UTC is 11:00 Riyadh, so the local day is the one named.
    ['2026-10-27 08:00:00', 7],
    ['2026-10-31 08:00:00', 3],
    ['2026-11-02 08:00:00', 1],
    ['2026-11-03 08:00:00', 0],
    ['2026-11-04 08:00:00', -1],
    // 22:00 UTC on 26 October is 01:00 Riyadh on 27 October: the local day
    // boundary, not the UTC one, is what decides.
    ['2026-10-26 22:00:00', 7],
]);

it('fires the latest applicable threshold and nothing before seven days out', function (string $nowUtc, ?ReminderThreshold $expected) {
    Carbon::setTestNow(Carbon::parse($nowUtc, 'UTC'));

    expect(ReminderSchedule::due($this->conference))->toBe($expected);
})->with([
    ['2026-10-20 08:00:00', null],
    ['2026-10-27 08:00:00', ReminderThreshold::Days7],
    // Five days out: the 7-day reminder, late, rather than nothing at all.
    ['2026-10-29 08:00:00', ReminderThreshold::Days7],
    ['2026-10-31 08:00:00', ReminderThreshold::Days3],
    ['2026-11-01 08:00:00', ReminderThreshold::Days3],
    ['2026-11-02 08:00:00', ReminderThreshold::Days1],
    ['2026-11-03 08:00:00', ReminderThreshold::Days1],
    // One minute past the deadline.
    ['2026-11-03 21:00:00', ReminderThreshold::Overdue],
    ['2026-11-10 08:00:00', ReminderThreshold::Overdue],
]);

it('answers nothing at all for a conference with no review deadline', function () {
    $this->conference->forceFill(['review_deadline' => null])->save();

    expect(ReminderSchedule::daysLeft($this->conference))->toBeNull()
        ->and(ReminderSchedule::due($this->conference))->toBeNull()
        ->and(ReminderSchedule::isSendHour($this->conference))->toBeFalse();
});

it('waits for the local send hour', function () {
    // 03:00 UTC is 06:00 Riyadh - before the 07:00 default.
    Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));
    expect(ReminderSchedule::isSendHour($this->conference))->toBeFalse();

    // 04:00 UTC is 07:00 Riyadh.
    Carbon::setTestNow(Carbon::parse('2026-10-27 04:00:00', 'UTC'));
    expect(ReminderSchedule::isSendHour($this->conference))->toBeTrue();

    // And every hour after it, so a run that was missed catches up the same day.
    Carbon::setTestNow(Carbon::parse('2026-10-27 19:00:00', 'UTC'));
    expect(ReminderSchedule::isSendHour($this->conference))->toBeTrue();
});

it('survives a daylight saving change in a zone that has one', function () {
    // Europe/London moves off BST at 02:00 on 25 October 2026. A day that is
    // 25 hours long must still count as one day.
    $conference = Conference::factory()->create([
        'timezone' => 'Europe/London',
        'review_deadline' => Carbon::parse('2026-10-26 12:00:00', 'UTC'),
    ]);

    Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));

    // Carbon 3 returns a float here and is signed by default (fact 4); rounding
    // is what keeps 1.958 from becoming 1.
    expect(ReminderSchedule::daysLeft($conference))->toBe(2);
});
