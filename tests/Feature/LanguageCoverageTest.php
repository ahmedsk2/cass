<?php

declare(strict_types=1);

use App\Enums\EmailTemplateKey;
use App\Enums\InvitationStatus;
use App\Enums\ReminderThreshold;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;

/**
 * Spec section 10. This is the test that makes "add Arabic by copying lang/en"
 * true rather than aspirational: it reads the files Plan 3 wrote, pulls every
 * __('submission.*') and __('mail.*') key out of them, and fails on any key
 * that does not resolve. Laravel returns the key itself for a miss, which
 * renders as `submission.fields.title` in a label and passes every other test
 * in the suite.
 */

/** Every file Plan 3 added or rewrote that may contain a translated string. */
$plan3Sources = [
    'resources/views/livewire/public/submission-form.blade.php',
    'resources/views/livewire/public/submission-status.blade.php',
    'resources/views/livewire/public/partials/authors.blade.php',
    'resources/views/livewire/public/partials/custom-fields.blade.php',
    'resources/views/livewire/public/partials/files.blade.php',
    'app/Livewire/Public/SubmissionForm.php',
    'app/Livewire/Public/SubmissionStatus.php',
];

it('sweeps every public partial, not only the ones this list was written with', function () use ($plan3Sources) {
    // The list above is hand-written, so a partial added by a later task is
    // silently outside the sweep and spec section 10 quietly stops being
    // enforced - invisibly, because the test still passes. The partials
    // directory belongs entirely to the Plan 3 pages, so its contents are the
    // one part of the list that can be checked rather than trusted.
    $partials = glob(base_path('resources/views/livewire/public/partials/*.blade.php')) ?: [];

    expect($partials)->not->toBeEmpty();

    $outside = [];

    foreach ($partials as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

        if (! in_array($relative, $plan3Sources, true)) {
            $outside[] = $relative;
        }
    }

    expect($outside)->toBe([]);
});

it('resolves every translation key plan 3 uses', function () use ($plan3Sources) {
    $missing = [];

    foreach ($plan3Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        // `__()`, `@lang()` and `trans()`, single- or double-quoted. The
        // narrower pattern this started as saw only single-quoted `__()`, so
        // any of the other four spellings was a key nothing checked - and a key
        // nothing checks renders as `submission.fields.title` in a label while
        // every test in the suite stays green.
        preg_match_all('/(?:__|@lang|trans)\(\s*[\'"]((?:submission|mail)\.[a-z0-9_.]+)[\'"]/', (string) file_get_contents($path), $matches);

        foreach (array_unique($matches[1]) as $key) {
            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('has a subject and a body for every template key', function () {
    foreach (EmailTemplateKey::cases() as $key) {
        expect(Lang::has('mail.templates.'.$key->value.'.subject'))->toBeTrue("mail.templates.{$key->value}.subject is missing")
            ->and(Lang::has('mail.templates.'.$key->value.'.body'))->toBeTrue("mail.templates.{$key->value}.body is missing");
    }
});

it('leaves no visible english hardcoded in the pages plan 3 added', function () use ($plan3Sources) {
    // Do NOT strip directives and tags with hand-written regexes. All three
    // failure shapes are in this plan's own views: `@php use
    // App\Enums\SubmissionWindow; @endphp` has no parentheses for a
    // `/@[a-z]+\s*\(.*?\)/` to match, `@foreach ((array) ($field->options ??
    // []) as $option)` defeats a non-greedy `.*?\)` because the first `)`
    // closes `(array`, and `:class="words > limit ? '…' : '…'"` ends a
    // `/<[^>]+>/` early on the `>` inside the attribute. Each of those leaves a
    // three-or-more-word "text node" that no language key can ever fix, which
    // would leave this task unable to reach rc=0 and the implementer deleting
    // the one test that proves spec section 10.
    //
    // So let Blade's own compiler turn every directive and echo into PHP, drop
    // the PHP and the script/style bodies (code, not prose), and let
    // strip_tags() - which tracks quotes, so an Alpine expression containing
    // `>` survives - leave only the text the page really prints.
    $allowed = ['KB', 'MB', 'PDF'];
    $offenders = [];

    foreach ($plan3Sources as $relative) {
        if (! str_ends_with($relative, '.blade.php')) {
            continue;
        }

        $compiled = Blade::compileString((string) file_get_contents(base_path($relative)));

        $stripped = (string) preg_replace([
            '/<\?php.*?\?>/s',
            '/<\?php.*$/s',
            '/<script\b[^>]*>.*?<\/script>/s',
            '/<style\b[^>]*>.*?<\/style>/s',
        ], ' ', $compiled);

        // strip_tags() throws away attribute values, so a hardcoded
        // `placeholder`, `title`, `alt` or `aria-label` - all of them visible
        // to a reader, and the last two the only text a screen reader gets -
        // would never be flagged. They are pulled out first and checked as
        // prose. An attribute whose value came from `{{ __(...) }}` is already
        // an empty string by this point: the PHP that produced it is gone.
        preg_match_all(
            '/\b(?:placeholder|title|alt|aria-label)\s*=\s*"([^"]*)"|\b(?:placeholder|title|alt|aria-label)\s*=\s*\'([^\']*)\'/i',
            $stripped,
            $attributes,
        );

        $text = strip_tags($stripped)."\n".implode("\n", [...$attributes[1], ...$attributes[2]]);

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

            if ($line === '' || in_array($line, $allowed, true)) {
                continue;
            }

            if (str_word_count($line) >= 3) {
                $offenders[] = "{$relative}: {$line}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Every file Plan 4 added or modified that may contain a translated string.
 *
 * Two of these are *modified*, not created, and they are here precisely because
 * of that: `ConferenceStatusActions.php` carries the reviewers link, the
 * assignments link, `reviewer.remind.*` and `reviewer.start.*`, and
 * `ConferenceInfolist.php` carries `reviewer.progress.*`. Those are the busiest
 * `reviewer.*` surface Plan 4 adds to the organizer panel, and leaving them out
 * means a typo in `reviewer.remind.action` renders the raw key string on the
 * conference page with this test still green - which is the exact failure this
 * test exists to prevent. The three organizer Blade views are here for the same
 * reason: the second case below scans only `.blade.php` entries.
 */
$plan4Sources = [
    'resources/views/livewire/public/accept-invitation.blade.php',
    'resources/views/filament/organizer/pages/members.blade.php',
    'resources/views/filament/organizer/resources/conferences/pages/assignments.blade.php',
    'resources/views/filament/organizer/resources/conferences/pages/reviewers.blade.php',
    'resources/views/filament/reviewer/pages/dashboard.blade.php',
    'resources/views/filament/reviewer/resources/submissions/pages/review-submission.blade.php',
    'app/Livewire/Public/AcceptInvitation.php',
    'app/Actions/Invitations/AcceptInvitation.php',
    'app/Actions/Organizations/ChangeMemberRole.php',
    'app/Actions/Organizations/InviteMember.php',
    'app/Actions/Organizations/RemoveMember.php',
    'app/Actions/Organizations/SetSubmissionNotifications.php',
    'app/Actions/Reviewers/InviteReviewer.php',
    'app/Actions/Reviewers/InviteReviewerList.php',
    'app/Actions/Reviews/AssignReviewers.php',
    'app/Actions/Reviews/AutoAssignReviewers.php',
    'app/Actions/Reviews/ReopenReview.php',
    'app/Actions/Reviews/SaveReviewDraft.php',
    'app/Actions/Reviews/SendReviewerReminders.php',
    'app/Actions/Reviews/SubmitReview.php',
    'app/Actions/Conferences/StartReviewing.php',
    'app/Filament/Organizer/Pages/Members.php',
    'app/Filament/Organizer/Resources/Conferences/Pages/ConferenceAssignments.php',
    'app/Filament/Organizer/Resources/Conferences/Pages/ConferenceReviewers.php',
    'app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php',
    'app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php',
    'app/Filament/Reviewer/Pages/Dashboard.php',
    'app/Filament/Reviewer/Resources/Submissions/Pages/ListSubmissions.php',
    'app/Filament/Reviewer/Resources/Submissions/Pages/ReviewSubmission.php',
    'app/Filament/Reviewer/Resources/Submissions/SubmissionResource.php',
    'app/Filament/Reviewer/Resources/Submissions/Tables/QueueTable.php',
    'app/Models/OrganizationInvitation.php',
    'app/Models/ReviewerInvitation.php',
    'app/Support/Reviews/ReviewFormSchema.php',
    'app/Support/Reviews/ReviewerList.php',
    'app/Support/Panels/PanelSwitch.php',
    'app/Notifications/MemberInvitation.php',
];

it('resolves every translation key plan 4 uses', function () use ($plan4Sources) {
    $missing = [];

    foreach ($plan4Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        // The trailing group cannot end on a dot, so a concatenated key such as
        // `__('members.invite.blocked.'.$status->value)` in
        // app/Actions/Invitations/AcceptInvitation.php is skipped rather than
        // captured as the literal prefix 'members.invite.blocked.' - for which
        // Lang::has() is false (Arr::get explodes it to a final empty segment)
        // and which no language file can ever satisfy. Those four keys are
        // asserted directly in the third case below, by enum case.
        // `decisions` is in the alternation even though Plan 4 predates that
        // file: Plan 5 rewrote two of the files in this very list
        // (ConferenceStatusActions, ConferenceInfolist) to call
        // `__('decisions.*')`, and two patterns that disagree about which
        // prefixes count would let a typo in one of them through whichever
        // case happens not to look.
        preg_match_all(
            "/__\\(\\s*'((?:members|reviewer|decisions)(?:\\.[a-z0-9_]+)+)'/",
            (string) file_get_contents($path),
            $matches,
        );

        foreach (array_unique($matches[1]) as $key) {
            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('leaves no visible english hardcoded in the pages plan 4 added', function () use ($plan4Sources) {
    // The same compile-then-strip approach as Plan 3's case, for the same
    // reason: hand-written regexes over Blade directives produce phantom
    // offenders that no language key can ever fix.
    $allowed = ['KB', 'MB', 'PDF'];
    $offenders = [];

    foreach ($plan4Sources as $relative) {
        if (! str_ends_with($relative, '.blade.php')) {
            continue;
        }

        $compiled = Blade::compileString((string) file_get_contents(base_path($relative)));

        $text = strip_tags((string) preg_replace([
            '/<\?php.*?\?>/s',
            '/<\?php.*$/s',
            '/<script\b[^>]*>.*?<\/script>/s',
            '/<style\b[^>]*>.*?<\/style>/s',
        ], ' ', $compiled));

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

            if ($line === '' || in_array($line, $allowed, true)) {
                continue;
            }

            if (str_word_count($line) >= 3) {
                $offenders[] = "{$relative}: {$line}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Every file Plan 5 added or rewrote that may contain a translated string. The
 * two *modified* files are in the list on purpose: most of Plan 5's
 * organizer-facing keys live in DecisionActions and ConferenceStatusActions
 * rather than in a view.
 */
$plan5Sources = [
    'app/Filament/Organizer/Resources/Conferences/Pages/ConferenceRanking.php',
    'app/Filament/Organizer/Resources/Conferences/Tables/DecisionActions.php',
    'app/Filament/Organizer/Resources/Conferences/Tables/ConferenceStatusActions.php',
    'app/Filament/Organizer/Resources/Conferences/Schemas/ConferenceInfolist.php',
    'app/Filament/Organizer/Resources/Submissions/Schemas/SubmissionInfolist.php',
    'app/Actions/Decisions/ApplyDecision.php',
    'app/Actions/Decisions/ApplyDecisions.php',
    'app/Actions/Decisions/SendDecisionEmails.php',
    'app/Actions/Decisions/SendOneDecisionEmail.php',
    'app/Actions/Conferences/MarkDecided.php',
    'app/Models/SubmissionDecision.php',
    'resources/views/filament/organizer/pages/conference-ranking.blade.php',
    'resources/views/livewire/public/submission-status.blade.php',
    'resources/views/filament/reviewer/pages/dashboard.blade.php',
];

it('resolves every translation key plan 5 uses', function () use ($plan5Sources) {
    $missing = [];

    foreach ($plan5Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        preg_match_all(
            '/(?:__|@lang|trans)\(\s*[\'"]((?:submission|mail|members|reviewer|decisions)\.[a-z0-9_.]+)[\'"]/',
            (string) file_get_contents($path),
            $matches,
        );

        foreach (array_unique($matches[1]) as $key) {
            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('leaves no visible english hardcoded in the pages plan 5 added', function () use ($plan5Sources) {
    // The same compiler-based sweep the Plan 3 case uses, over the Blade files
    // of this list. Do NOT strip directives with hand-written regexes - the
    // reasoning is in the Plan 3 case above and all three failure shapes are in
    // this plan's own views too.
    $allowed = ['KB', 'MB'];
    $offenders = [];

    foreach ($plan5Sources as $relative) {
        if (! str_ends_with($relative, '.blade.php')) {
            continue;
        }

        $compiled = Blade::compileString((string) file_get_contents(base_path($relative)));

        $stripped = (string) preg_replace([
            '/<\?php.*?\?>/s',
            '/<\?php.*$/s',
            '/<script\b[^>]*>.*?<\/script>/s',
            '/<style\b[^>]*>.*?<\/style>/s',
        ], ' ', $compiled);

        preg_match_all(
            '/\b(?:placeholder|title|alt|aria-label)\s*=\s*"([^"]*)"|\b(?:placeholder|title|alt|aria-label)\s*=\s*\'([^\']*)\'/i',
            $stripped,
            $attributes,
        );

        $text = strip_tags($stripped)."\n".implode("\n", [...$attributes[1], ...$attributes[2]]);

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

            if ($line === '' || in_array($line, $allowed, true)) {
                continue;
            }

            if (str_word_count($line) >= 3) {
                $offenders[] = "{$relative}: {$line}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('has an english line for every reminder threshold and reviewer status', function () {
    foreach (ReminderThreshold::cases() as $threshold) {
        expect($threshold->getLabel())->not->toBe('');
    }

    // The four invitation states the accept page branches on each need a
    // sentence, or an invitee meets a blank box.
    foreach (InvitationStatus::cases() as $status) {
        expect(Lang::has('members.invite.blocked.'.$status->value))->toBeTrue($status->value);
    }
});

/**
 * Every file Plans 1 and 2 left in English, which spec section 10 says must
 * live in a language file before any Arabic work. The list includes two
 * ORGANIZER views (the dashboard banners and the short-link page) that no
 * existing backlog item mentions and that no previous sweep covered - they are
 * the same vintage and the same problem.
 */
$plan6Sources = [
    'resources/views/components/layouts/public.blade.php',
    'resources/views/components/layouts/conference.blade.php',
    'resources/views/public/landing.blade.php',
    'resources/views/public/about.blade.php',
    'resources/views/public/privacy.blade.php',
    'resources/views/public/terms.blade.php',
    'resources/views/public/conference.blade.php',
    'resources/views/public/partials/submit-cta.blade.php',
    'resources/views/livewire/public/contact-form.blade.php',
    'resources/views/livewire/public/register-organization.blade.php',
    'resources/views/pdf/conference-poster.blade.php',
    'resources/views/emails/contact-message.blade.php',
    'resources/views/filament/organizer/pages/dashboard.blade.php',
    'resources/views/filament/organizer/resources/conferences/pages/short-link.blade.php',
];

// The key-resolution case below reads every file in that list. The
// visible-English case after it reads every file EXCEPT the one mail view:
// resources/views/emails/contact-message.blade.php is a <x-mail::message>
// MARKDOWN document, and Blade::compileString() + strip_tags() leave its
// syntax behind as the literal lines `#` and `** ** &lt; &gt;` (measured,
// not guessed). No language key can remove those, and an allow-list entry
// would only hide them. Task 8 moved that view's three English strings
// into lang/en/mail.php, so the key-resolution case is what proves it.
$plan6Views = array_values(array_diff($plan6Sources, [
    'resources/views/emails/contact-message.blade.php',
]));

it('resolves every translation key plans 1 and 2 now use', function () use ($plan6Sources) {
    $missing = [];

    foreach ($plan6Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        // `public`, `conference` and `poster` are this task's three new files;
        // the rest of the alternation is what the Plan 5 case already allows,
        // because eight of these strings are REUSED from submission.* and
        // members.* rather than duplicated (Plan 6 fact 39).
        preg_match_all(
            '/(?:__|@lang|trans)\(\s*[\'"]((?:public|conference|poster|submission|mail|members|reviewer|decisions|admin|domain|legacy)\.[a-z0-9_.]+)[\'"]/',
            (string) file_get_contents($path),
            $matches,
        );

        foreach (array_unique($matches[1]) as $key) {
            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('leaves no visible english at all in the plans 1 and 2 pages', function () use ($plan6Views) {
    // The threshold is ONE word, not three.
    //
    // Every existing case in this file uses `str_word_count($line) >= 3`, and
    // that is why this task exists at all: "About", "Contact", "Terms",
    // "Privacy", "Organizer login", "Call for abstracts", "What to prepare",
    // "Submit abstract", "Total scans", "Deadline", "Venue" and "Tracks" are
    // every one of them two words or fewer. A case copied from the Plan 5 one
    // would pass with the navigation bar, both footers and half the conference
    // page still in English.
    //
    // The allow-list is what is genuinely not prose: the brand, the four step
    // numerals on the landing page, the two file-size units, the file type and
    // the two typographic separators.
    $allowed = ['CASS', '01', '02', '03', '04', 'KB', 'MB', 'PDF', '·', '–', '—'];
    $offenders = [];

    foreach ($plan6Views as $relative) {
        $compiled = Blade::compileString((string) file_get_contents(base_path($relative)));

        $stripped = (string) preg_replace([
            '/<\?php.*?\?>/s',
            '/<\?php.*$/s',
            '/<script\b[^>]*>.*?<\/script>/s',
            '/<style\b[^>]*>.*?<\/style>/s',
        ], ' ', $compiled);

        preg_match_all(
            '/\b(?:placeholder|title|alt|aria-label)\s*=\s*"([^"]*)"|\b(?:placeholder|title|alt|aria-label)\s*=\s*\'([^\']*)\'/i',
            $stripped,
            $attributes,
        );

        $text = strip_tags($stripped)."\n".implode("\n", [...$attributes[1], ...$attributes[2]]);

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

            if ($line === '' || in_array($line, $allowed, true)) {
                continue;
            }

            $offenders[] = "{$relative}: {$line}";
        }
    }

    expect($offenders)->toBe([]);
});

it('carries the countdown phrases as data attributes rather than words in a script', function () {
    // __() cannot reach a .js module, and the script's English is built by
    // inline ternaries (days === 1 ? '' : 's') that have no Arabic analogue -
    // Arabic has six plural forms. So the view passes finished phrases and the
    // script chooses between them.
    $source = (string) file_get_contents(base_path('resources/js/countdown.js'));

    expect($source)->not->toContain("' day'")
        ->and($source)->not->toContain("'days'")
        ->and($source)->not->toContain("' left'")
        ->and($source)->toContain('dataset');

    foreach (['countdown.days', 'countdown.hours', 'countdown.minutes', 'countdown.passed'] as $key) {
        expect(Lang::has('submission.'.$key))->toBeTrue($key);
    }
});

it('gives both public layouts a direction that a translation can flip', function (string $view) {
    $compiled = Blade::compileString((string) file_get_contents(base_path($view)));

    // Both already set lang=; neither set dir=, so an `ar` locale would render
    // left to right and "Arabic is a copy of lang/en" would be false in one
    // attribute.
    expect($compiled)->toContain('dir=')
        ->and(Lang::get('public.dir'))->toBe('ltr');
})->with([
    'resources/views/components/layouts/public.blade.php',
    'resources/views/components/layouts/conference.blade.php',
]);

it('resolves every translation key the php classes use, not only the ones in the view lists', function () {
    // Every $planNSources array in this file is a hand-written list of BLADE
    // views, so no case here checks a single __() call in app/ - and Plan 6
    // put 158 new admin./domain./legacy. keys into twenty-three classes
    // (ConferencesTable, SubmissionsTable, ReviewInfolist,
    // EditOrganizationProfile, ImportLegacy, CustomDomainVerified and the
    // rest). Laravel returns the key itself on a miss, which renders as a
    // literal `admin.reviews.title` in a column label, a modal heading or an
    // email subject while every other test in the suite still passes.
    //
    // Derived from the directory rather than a list, so a class added by a
    // later task is inside the sweep on the day it is written.
    $namespaces = collect(glob(lang_path('en/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->values();

    expect($namespaces)->not->toBeEmpty();

    $pattern = '/(?:__|@lang|trans)\(\s*[\'"]((?:'.$namespaces->implode('|').')\.[a-z0-9_.]+)[\'"]/';

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $missing = [];
    $checked = 0;

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches);

        foreach (array_unique($matches[1]) as $key) {
            // A trailing dot is a key built by concatenation -
            // __('members.invite.blocked.'.$status->value) and
            // __('mail.templates.'.$key) are the two in this codebase - so the
            // literal prefix resolves to nothing by design. Both families are
            // already pinned case by case elsewhere in this file.
            if (str_ends_with($key, '.')) {
                continue;
            }

            $checked++;

            if (! Lang::has($key)) {
                $missing[] = $key.' (used in '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).')';
            }
        }
    }

    // If this drops to nothing the regex or the directory walk has broken, and
    // the case would pass while checking no key at all.
    expect($checked)->toBeGreaterThan(200)
        ->and($missing)->toBe([]);
});
