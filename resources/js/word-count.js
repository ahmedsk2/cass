// The browser half of App\Support\Text\WordCounter. The two must agree, because
// the counter next to the textarea is what an author trusts and the server rule
// is what actually refuses the submission. Same two rules, same order:
//
//   1. split on whitespace *including* the characters a paste from Word brings
//      with it (U+00A0, U+202F, U+3000). JavaScript expresses that as the
//      Unicode property \p{White_Space}, which already covers all of them;
//      PCRE has no such property, which is why the PHP side lists them by hand.
//      Both sides split on the same set - they just spell it differently.
//   2. count a token only if it contains a letter or a digit in any script.
//
// It hangs off `window` rather than registering an Alpine component, because
// Livewire injects and starts Alpine from a classic script before this deferred
// module runs - an `alpine:init` listener here would be registered too late.
// An inline `x-data` expression that calls a global has no such ordering
// problem: nothing calls it until the author types.
const SEPARATORS = /\p{White_Space}+/u;
const HAS_LETTER_OR_DIGIT = /[\p{L}\p{N}]/u;

export function countWords(text) {
    if (typeof text !== 'string') {
        return 0;
    }

    return text
        .trim()
        .split(SEPARATORS)
        .filter((token) => token !== '' && HAS_LETTER_OR_DIGIT.test(token))
        .length;
}

window.cassCountWords = countWords;
