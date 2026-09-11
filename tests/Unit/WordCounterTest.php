<?php

declare(strict_types=1);

use App\Support\Text\WordCounter;

it('counts the words an author would count', function (string $text, int $expected) {
    expect(WordCounter::count($text))->toBe($expected);
})->with([
    'empty' => ['', 0],
    'whitespace only' => ["  \n\t ", 0],
    'plain sentence' => ['Early mobilisation after cardiac surgery', 5],
    // A hyphenated term is one word to a human and to every abstract limit
    // anyone has ever been held to.
    'hyphenated compound' => ['A double-blind placebo-controlled trial', 4],
    // An em dash on its own is punctuation, not a word.
    'bare punctuation is not a word' => ['Results — significant', 2],
    'statistics are words' => ['The difference was significant (p<0.05).', 5],
    'numbers count' => ['We enrolled 120 children in 3 centres', 7],
    'collapses runs of whitespace' => ["Two\n\n\nlines   apart", 3],
    // A non-breaking space is what arrives when an author pastes from Word.
    // PCRE's \s does not match U+00A0, so the pattern lists it explicitly.
    'non-breaking space splits words' => ["Riyadh\u{00A0}Saudi\u{00A0}Arabia", 3],
    'narrow no-break space splits words' => ["120\u{202F}children enrolled", 3],
    'arabic counts like any other script' => ['الرعاية الحرجة للأطفال', 3],
    'emoji alone is not a word' => ['Results 🎉 improved', 2],
]);

it('is not fooled by leading or trailing whitespace', function () {
    expect(WordCounter::count("   Early mobilisation   \n"))->toBe(2);
});
