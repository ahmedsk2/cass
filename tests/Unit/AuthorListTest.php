<?php

declare(strict_types=1);

use App\Support\Legacy\AuthorList;

// Pure: a string in, a list of arrays out. No database, no models.

it('splits a byline on each of the five separators the legacy data uses', function (string $byline, array $names) {
    expect(array_column(AuthorList::parse($byline), 'name'))->toBe($names);
})->with([
    'comma' => ['Dr Alia Example, Dr Badr Example', ['Dr Alia Example', 'Dr Badr Example']],
    // 47 occurrences in the real data.
    'crlf' => ["Dr Alia Example\r\nDr Badr Example", ['Dr Alia Example', 'Dr Badr Example']],
    'ampersand' => ['Sarah A & Zainab B & Mohammed C', ['Sarah A', 'Zainab B', 'Mohammed C']],
    'bullet' => ['Dr Alia Example • Dr Badr Example', ['Dr Alia Example', 'Dr Badr Example']],
    'semicolon' => ['Dr Alia Example; Dr Badr Example', ['Dr Alia Example', 'Dr Badr Example']],
    'mixed' => ["Dr Alia Example,\r\nDr Badr Example & Dr Carim Example", ['Dr Alia Example', 'Dr Badr Example', 'Dr Carim Example']],
]);

it('strips the journal markers the legacy bylines carry inside the names', function (string $byline, array $names) {
    expect(array_column(AuthorList::parse($byline), 'name'))->toBe($names);
})->with([
    // Real shapes: superscript affiliation numbers, asterisks, and the literal
    // word "corresponding" pasted in from a manuscript.
    'superscripts' => ['Ibrahim A,1 Reem B,2', ['Ibrahim A', 'Reem B']],
    'asterisk' => ['Ali F. Atwah 1,*, Ema B 2', ['Ali F. Atwah', 'Ema B']],
    'glued digits' => ['Isaq A. AlMughaizel1* , Abdulhameed A. Al-Bunyan1', ['Isaq A. AlMughaizel', 'Abdulhameed A. Al-Bunyan']],
    'the word itself' => ['Ibrahim A, Reem B,corresponding', ['Ibrahim A', 'Reem B']],
]);

it('drops empty fragments and trims endemic trailing whitespace', function () {
    expect(AuthorList::parse("Dr Alia Example ,, \r\n  "))->toHaveCount(1)
        ->and(AuthorList::parse('')->count ?? count(AuthorList::parse('')))->toBe(0);
});

it('marks the presenter from the separate legacy column', function () {
    $authors = AuthorList::parse('Dr Alia Example, Dr Badr Example', presenters: 'Dr Badr Example');

    expect($authors[0]['is_presenter'])->toBeFalse()
        ->and($authors[1]['is_presenter'])->toBeTrue();
});

it('marks a presenter who is not in the author list at all, by adding them', function () {
    // Real rows 12 and 13: presenter_names holds somebody `authors` does not.
    // Dropping them would lose the one person who actually stood up.
    $authors = AuthorList::parse('Dr Alia Example', presenters: 'Dr Kauther Example');

    expect(array_column($authors, 'name'))->toBe(['Dr Alia Example', 'Dr Kauther Example'])
        ->and($authors[1]['is_presenter'])->toBeTrue();
});

it('gives the contact address to the author whose name it looks like, and nobody else', function () {
    $authors = AuthorList::parse(
        'Dr Alia Example, Dr Badr Example',
        contactEmail: 'badr.example@example.org',
    );

    expect($authors[1]['email'])->toBe('badr.example@example.org')
        ->and($authors[1]['is_corresponding'])->toBeTrue()
        ->and($authors[0]['email'])->toBeNull()
        ->and($authors[0]['is_corresponding'])->toBeFalse();
});

it('falls back to the first author when the address matches nobody', function () {
    $authors = AuthorList::parse(
        'Dr Alia Example, Dr Badr Example',
        contactEmail: 'research.office@example.org',
    );

    // Somebody has to be corresponding - it is the only address the abstract
    // has - and the first author is the conventional answer. The import
    // reports every row that took this branch.
    expect($authors[0]['is_corresponding'])->toBeTrue()
        ->and($authors[0]['email'])->toBe('research.office@example.org')
        ->and(AuthorList::matchedContact('Dr Alia Example, Dr Badr Example', 'research.office@example.org'))->toBeFalse();
});

it('decides whether a legacy affiliation is really an affiliation', function (string $affiliation, string $authors, bool $isAffiliation) {
    expect(AuthorList::looksLikeAffiliation($affiliation, $authors))->toBe($isAffiliation);
})->with([
    // Conference 6: clean institutional strings.
    ['Example Central Hospital', 'Dr Dalia Example', true],
    ['Department of Pediatrics, Eastern Health Cluster', 'Dr Dalia Example', true],
    ['None', 'Dr Dalia Example', true],
    // Conference 5: the column holds a person who is also in the byline.
    ['Dr Alia Example', 'Dr Alia Example, Dr Badr Example', false],
    // Rows 12 and 13: the whole author list, in the affiliation column.
    ['Dr Alia Example , Dr Badr Example , Dr Carim Example', 'Dr Alia Example', false],
    // Row 7: a research question. Not a name, not an institution - which is
    // why this returns true and the IMPORT reports it rather than the parser
    // pretending to know.
    ['How does mobilisation affect recovery', 'Dr Alia Example', true],
]);
