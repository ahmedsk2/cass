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

it('still counts an abstract carrying a byte that is not valid utf-8', function () {
    // Every preg_* call here carries /u, and PCRE refuses a subject that is not
    // valid UTF-8 by answering false. Left unhandled that makes count() return
    // 0, so a 5000-word paste with one stray byte from a bad copy-paste passes
    // any word limit and is stored as `word_count = 0`. The bad bytes are
    // dropped and what is left is counted.
    $text = "Early mobilisation after cardiac surgery in \xFFchildren";

    expect(mb_check_encoding($text, 'UTF-8'))->toBeFalse()
        ->and(WordCounter::count($text))->toBe(7);
});
