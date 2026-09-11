<?php

declare(strict_types=1);

use App\Support\Submissions\ReferencePrefix;
use Carbon\CarbonImmutable;

it('takes the initials of the words in the name and the two-digit year', function (string $name, ?string $startsAt, string $expected) {
    expect(ReferencePrefix::derive($name, $startsAt === null ? null : CarbonImmutable::parse($startsAt)))->toBe($expected);
})->with([
    // The spec's own example shape: four initials plus the year of the first day.
    ['Gulf Pediatric Critical Care 2026', '2026-11-03', 'GPCC26'],
    // Digits and short joining words are not initials.
    ['The Annual Meeting of the Saudi Society', '2027-02-01', 'AMSS27'],
    // Six letters is the cap, so a seven-word name loses its tail.
    ['Alpha Beta Gamma Delta Epsilon Zeta Eta', '2026-05-05', 'ABGDEZ26'],
    // One word gives one initial, which nobody could recognise on a badge, so
    // the fallback is the first four letters instead.
    ['Symposium', '2026-09-09', 'SYMP26'],
    // Non-latin names have no A-Z initials at all; fall back to a fixed stem so
    // the reference is still printable and unique per conference.
    ['ملتقى الرعاية الحرجة', '2026-04-04', 'CASS26'],
    // No start date: the year the conference row is created.
    ['Winter School', null, 'WS'.date('y')],
]);

it('never exceeds the twelve characters the column allows and is upper-case alphanumeric', function () {
    $prefix = ReferencePrefix::derive('Alpha Beta Gamma Delta Epsilon Zeta Eta Theta Iota', CarbonImmutable::parse('2026-01-01'));

    expect(strlen($prefix))->toBeLessThanOrEqual(12)
        ->and($prefix)->toMatch('/^[A-Z0-9]+$/');
});
