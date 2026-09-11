<?php

declare(strict_types=1);

use App\Support\Reviews\ReviewerList;

it('parses the two shapes spec 5.4 names', function () {
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan <omar@example.org>
    sara@example.org
    TXT);

    expect($parsed->entries)->toBe([
        ['name' => 'Dr Omar Khan', 'email' => 'omar@example.org'],
        ['name' => null, 'email' => 'sara@example.org'],
    ])->and($parsed->errors)->toBe([])
        ->and($parsed->duplicates)->toBe([]);
});

it('parses the shapes a spreadsheet paste actually produces', function () {
    // Comma, semicolon and tab separated, in both orders. Whichever half looks
    // like an address is the address; the other half is the name.
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan, omar@example.org
    sara@example.org; Dr Sara Al-Harbi
    Dr Layla Ahmed	layla@example.org
      "Dr Noor Ali" <noor@example.org>
    TXT);

    expect(array_column($parsed->entries, 'email'))
        ->toBe(['omar@example.org', 'sara@example.org', 'layla@example.org', 'noor@example.org'])
        ->and(array_column($parsed->entries, 'name'))
        ->toBe(['Dr Omar Khan', 'Dr Sara Al-Harbi', 'Dr Layla Ahmed', 'Dr Noor Ali']);
});

it('lower-cases addresses, drops blank lines and reports every bad line with its number', function () {
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan <OMAR@Example.ORG>

    not an address at all
    Dr Nobody <@example.org>
    TXT);

    expect(array_column($parsed->entries, 'email'))->toBe(['omar@example.org'])
        ->and($parsed->errors)->toHaveCount(2)
        ->and($parsed->errors[0])->toContain('3')
        ->and($parsed->errors[0])->toContain('not an address at all')
        ->and($parsed->errors[1])->toContain('4');
});

it('deduplicates on the address and keeps the first name it was given', function () {
    $parsed = ReviewerList::parse(<<<'TXT'
    Dr Omar Khan <omar@example.org>
    omar@example.org
    Someone Else <OMAR@EXAMPLE.ORG>
    TXT);

    expect($parsed->entries)->toBe([['name' => 'Dr Omar Khan', 'email' => 'omar@example.org']])
        ->and($parsed->duplicates)->toBe(['omar@example.org']);
});

it('answers empty for empty input rather than one blank entry', function () {
    $parsed = ReviewerList::parse("\n  \n\t\n");

    expect($parsed->entries)->toBe([])->and($parsed->errors)->toBe([]);
});

it('stops at the entry cap and says so', function () {
    // The textarea takes 20,000 characters - about 2,500 bare addresses - and
    // InviteReviewerList loops synchronously, about eight queries plus a
    // rendered template plus a queued mailable per entry, inside one Livewire
    // POST that php-fpm and nginx both abandon at 60 seconds. A paste past the
    // cap must be a sentence, not a 504 halfway through a batch whose already
    // delivered links the retry would invalidate.
    $text = collect(range(1, 150))->map(fn (int $i): string => "r{$i}@example.org")->implode("\n");

    $parsed = ReviewerList::parse($text);

    expect($parsed->entries)->toHaveCount(ReviewerList::MAX_ENTRIES)
        ->and($parsed->errors)->toHaveCount(1)
        ->and($parsed->errors[0])->toContain((string) ReviewerList::MAX_ENTRIES);
});
