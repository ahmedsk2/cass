<?php

declare(strict_types=1);

use App\Actions\Submissions\ExportSubmissionsCsv;
use App\Enums\Decision;
use App\Enums\EmailTemplateKey;
use App\Enums\InvitationStatus;
use App\Enums\ReminderThreshold;
use App\Models\Submission;
use App\Support\Scoring\RankingRows;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

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

/**
 * Plan 7: the PHP classes every earlier sweep stopped at. The backlog named
 * them - every enum's getLabel(), both export headings, the publishing
 * checklist and the admin tables Plans 1 and 2 wrote - and none of them is a
 * Blade view, which is why no $planNSources case above ever read a line of
 * them: every visible-English case in this file skips a file that does not
 * end in .blade.php.
 *
 * NOT here, on purpose:
 * - app/Enums/Decision.php. Its label is `{{decision}}`'s value in every
 *   decision letter, so it follows the letter's language rather than the
 *   panel's and moves with spec section 14's bilingual templates (backlog).
 *   The enum case below pins it as the one label still spelled out.
 * - app/Enums/DemoStage.php and app/Enums/SubmissionWindow.php. Neither has
 *   a label: DemoStage's values are the `--stage=` arguments an operator
 *   types, and the public views turn SubmissionWindow into submission.window.*
 *   keys themselves.
 *
 * Tasks 3 to 6 append their own files to the end of this list.
 */
$plan7Sources = [
    'app/Enums/ConferenceStatus.php',
    'app/Enums/CustomFieldType.php',
    'app/Enums/EmailLogStatus.php',
    'app/Enums/EmailTemplateKey.php',
    'app/Enums/InvitationStatus.php',
    'app/Enums/OrganizationRole.php',
    'app/Enums/OrganizationStatus.php',
    'app/Enums/OrganizationType.php',
    'app/Enums/PosterSize.php',
    'app/Enums/PresentationPreference.php',
    'app/Enums/ReminderThreshold.php',
    'app/Enums/ReviewMode.php',
    'app/Enums/ReviewQuestionType.php',
    'app/Enums/ReviewStatus.php',
    'app/Enums/ReviewerStatus.php',
    'app/Enums/SubmissionStatus.php',
    'app/Support/Scoring/RankingRows.php',
    'app/Actions/Submissions/ExportSubmissionsCsv.php',
    'app/Actions/Conferences/PublishConference.php',
    'app/Filament/Admin/Resources/Conferences/Tables/ConferencesTable.php',
    'app/Filament/Admin/Resources/EmailLogs/Tables/EmailLogsTable.php',
    'app/Filament/Admin/Resources/Organizations/Tables/OrganizationsTable.php',
    // The purge modal both admin tables render. Its own words have been keys
    // since Plan 6; it is here so this list's Blade branch has a file to read
    // from day one rather than first running on a later task's view.
    'resources/views/filament/admin/partials/purge-counts.blade.php',
];

/**
 * Literals in the files above that read as English and are not interface.
 * Each entry is exact text, never a pattern, so a new hardcoded label in the
 * same file is still caught.
 */
$plan7NotProse = [
    // EmailTemplateKey::sampleValues() is the template editor's preview DATA:
    // a person's name, an abstract title, a conference and a society name -
    // things somebody types, which no translation changes - plus the two
    // values that mirror what the application really substitutes. `deadline`
    // is how SubmitAbstract formats it (`->format('j F Y, H:i')`, which is not
    // localised), and `decision` is Decision::getLabel(), which stays English
    // until the bilingual templates (see above). `reason` is what an admin
    // types into the reject box.
    'app/Enums/EmailTemplateKey.php' => [
        'Dr Sara Al-Harbi',
        'Dr Omar Khan',
        'Early mobilisation after cardiac surgery',
        'Gulf Pediatric Critical Care 2026',
        'Gulf Pediatric Society',
        '3 November 2026, 23:59 (Asia/Riyadh)',
        'Accepted for oral presentation',
        'The society could not be verified from the details given.',
    ],
];

/**
 * The visible text of a Blade view: the Plan 6 sweep, unchanged - compile,
 * drop the PHP and the script/style bodies, then strip_tags(), plus the four
 * attributes a reader or a screen reader is given.
 *
 * @return list<string>
 */
$plan7BladeText = static function (string $blade): array {
    $stripped = (string) preg_replace([
        '/<\?php.*?\?>/s',
        '/<\?php.*$/s',
        '/<script\b[^>]*>.*?<\/script>/s',
        '/<style\b[^>]*>.*?<\/style>/s',
    ], ' ', Blade::compileString($blade));

    preg_match_all(
        '/\b(?:placeholder|title|alt|aria-label)\s*=\s*"([^"]*)"|\b(?:placeholder|title|alt|aria-label)\s*=\s*\'([^\']*)\'/i',
        $stripped,
        $attributes,
    );

    $text = strip_tags($stripped)."\n".implode("\n", [...$attributes[1], ...$attributes[2]]);
    $lines = [];

    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line) ?? '');

        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
};

/**
 * The string literals of a PHP file that read as English.
 *
 * Blade::compileString() has nothing to say about a class, so this reads
 * PHP's own tokens. Comments and docblocks are never string tokens, and a key
 * inside __('...') never reads as English, so neither needs handling. What is
 * left is the shape of the literal itself:
 *
 * - a capitalised word on its own reads as English: 'Draft', 'Owner',
 *   'Platform-wide', 'Decision:'. An identifier does not, even with capitals in
 *   it: 'reviewAssignments', 'Content-Type', 'X-CASS-Log', 'Y-m-d-His',
 *   'App\Filament\Admin', 'CASS';
 * - a literal with a space in it reads as English when it holds a capitalised
 *   word, or a word of two or more letters standing between spaces: 'Letters
 *   sent', 'open for submissions', 'A4 poster (210 x 297 mm)'. A date format
 *   does not ('j M Y, H:i'), and nor does a MIME type ('text/csv;
 *   charset=UTF-8');
 * - a fragment of an interpolated "..." string reads as English when a word
 *   touches a space, because "{$record->name} approved" is a sentence whose
 *   only literal word is lower-case, while "livewire-tmp/{$file}" is a path.
 *
 * Two places a sentence-shaped literal is still an identifier are skipped by
 * position, not by list: an array key ('Content-Type' => ...,
 * ['submissions as decided_count' => fn ...]) and a subscript
 * ($counts['private files']). Anything else that reads as English and is not
 * interface - an SQL fragment, a rel="" value - goes in $plan7NotProse, by
 * exact text and with a reason, so a reviewer sees every exception.
 *
 * The blind spot, stated rather than hidden: ONE lower-case word on its own
 * ('yes') is an identifier to these rules. The runtime cases - the enum and
 * export cases below, and tests/Feature/Admin/AdminTableLanguageTest.php -
 * are what cover that shape, and the labels Filament makes up from a column
 * name, which no literal holds at all.
 *
 * @return list<string> "line: text"
 */
$plan7PhpProse = static function (string $php): array {
    $tokens = array_values(array_filter(
        token_get_all($php),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $found = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $previous = $tokens[$i - 1] ?? null;
        $beforePrevious = $tokens[$i - 2] ?? null;
        $next = $tokens[$i + 1] ?? null;

        if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
            continue;
        }

        if ($previous === '[' && $next === ']' && is_array($beforePrevious) && $beforePrevious[0] === T_VARIABLE) {
            continue;
        }

        if ($token[0] === T_ENCAPSED_AND_WHITESPACE) {
            $text = $token[1];
            $prose = preg_match('/\s\p{L}{2,}|\p{L}{2,}\s/u', $text) === 1;
        } else {
            $text = substr($token[1], 1, -1);
            $prose = preg_match('/\s/u', $text) === 1
                ? preg_match('/(?<!\p{L})\p{Lu}\p{Ll}+|(?<!\S)\p{L}{2,}(?!\S)/u', $text) === 1
                : preg_match('/^\p{Lu}\p{Ll}+(?:-\p{Ll}+)*\p{P}?$/u', $text) === 1;
        }

        if ($prose) {
            $found[] = $token[2].': '.$text;
        }
    }

    return $found;
};

it('resolves every translation key plan 7 uses', function () use ($plan7Sources) {
    // The namespaces come from lang/en itself, as in the app-wide case above,
    // so a later task that opens a new language file is checked without
    // anybody remembering to widen a pattern. A key ending in a dot is built by
    // concatenation and is skipped, as there.
    $namespaces = collect(glob(lang_path('en/*.php')) ?: [])
        ->map(fn (string $path): string => basename($path, '.php'))
        ->implode('|');

    $missing = [];
    $checked = 0;

    foreach ($plan7Sources as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("Expected {$relative} to exist.");

        preg_match_all(
            '/(?:__|@lang|trans)\(\s*[\'"]((?:'.$namespaces.')\.[a-z0-9_.]+)[\'"]/',
            (string) file_get_contents($path),
            $matches,
        );

        foreach (array_unique($matches[1]) as $key) {
            if (str_ends_with($key, '.')) {
                continue;
            }

            $checked++;

            if (! Lang::has($key)) {
                $missing[] = "{$key} (used in {$relative})";
            }
        }
    }

    // 163 today: 67 enum labels, 32 export keys, 8 blockers, 52 keys on the
    // three admin tables (16 of them the Plan 6 purge action's) and 4 in the
    // purge modal. Later tasks only add to the list.
    expect($checked)->toBeGreaterThanOrEqual(163)
        ->and($missing)->toBe([]);
});

it('leaves no visible english in the files plan 7 swept', function () use ($plan7Sources, $plan7NotProse, $plan7BladeText, $plan7PhpProse) {
    // Blade views get the Plan 6 sweep at its Plan 6 threshold - ONE word,
    // with the same allow-list of things that are not prose. PHP files get
    // the token sweep above. A later task may append either.
    $allowed = ['CASS', 'KB', 'MB', 'PDF', '·', '–', '—'];
    $offenders = [];

    foreach ($plan7Sources as $relative) {
        $source = (string) file_get_contents(base_path($relative));

        if (str_ends_with($relative, '.blade.php')) {
            foreach ($plan7BladeText($source) as $line) {
                if (! in_array($line, $allowed, true)) {
                    $offenders[] = "{$relative}: {$line}";
                }
            }

            continue;
        }

        $excused = $plan7NotProse[$relative] ?? [];
        $seen = [];

        foreach ($plan7PhpProse($source) as $found) {
            [, $text] = explode(': ', $found, 2);

            if (in_array($text, $excused, true)) {
                $seen[] = $text;
            } else {
                $offenders[] = "{$relative}:{$found}";
            }
        }

        // An exception nobody needs any more is an exception nobody reviews:
        // an entry whose text has left the file is reported too.
        foreach (array_diff($excused, $seen) as $stale) {
            $offenders[] = "{$relative}: '{$stale}' is excused in \$plan7NotProse but no longer in the file";
        }
    }

    expect($offenders)->toBe([]);
});

it('looks every enum label up rather than spelling it, except the decision', function () {
    // A locale with no language file and no fallback, so __() hands back the
    // key it was given. A label that comes back as a key was looked up; one
    // that comes back as English was spelled out in the enum. Derived from
    // the directory rather than the list above, so an enum a later task adds
    // is inside the sweep on the day it is written.
    app()->setLocale('xx');
    app('translator')->setFallback('xx');

    $spelled = [];
    $checked = 0;

    foreach (glob(app_path('Enums/*.php')) ?: [] as $path) {
        $enum = 'App\\Enums\\'.basename($path, '.php');

        if (! is_a($enum, HasLabel::class, true) || $enum === Decision::class) {
            continue;
        }

        foreach ($enum::cases() as $case) {
            $checked++;
            $key = 'enums.'.Str::snake(class_basename($enum)).'.'.$case->value;

            if ($case->getLabel() !== $key) {
                $spelled[] = "{$enum}::{$case->name} is '{$case->getLabel()}', not {$key}";
            }
        }
    }

    // The exception, pinned so that translating it is a decision somebody
    // makes on purpose: a decision letter's `{{decision}}` has to follow the
    // LETTER's language, which is the bilingual-templates item of spec
    // section 14, not this sweep.
    expect($checked)->toBeGreaterThanOrEqual(67)
        ->and($spelled)->toBe([])
        ->and(Decision::AcceptedOral->getLabel())->toBe('Accepted for oral presentation');
});

it('looks both export heading rows up, and the yes and no inside a cell', function () {
    // The same keyless locale as the enum case. The ranking headings are a
    // plain list; the submission list is read off the streamed file, because
    // its yes/no is a single lower-case word inside a data cell - the one
    // shape the token sweep above cannot see.
    app()->setLocale('xx');
    app('translator')->setFallback('xx');

    // Keys in MySQL's order: a json column hands an object back shorter key
    // first, where SQLite keeps the order written, and the cell follows it.
    $submission = Submission::factory()->submitted()->create([
        'custom_field_values' => ['first_time' => false, 'needs_projector' => true],
    ]);

    $response = app(ExportSubmissionsCsv::class)
        ->handle(Submission::query()->whereKey($submission->getKey()), 'submissions.csv');

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    $rows = array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        preg_split('/\r?\n/', trim(substr($csv, 3))) ?: [],
    );

    $spelled = static fn (array $headings): array => array_values(array_filter(
        $headings,
        static fn (?string $heading): bool => preg_match('/^export\.headings\.[a-z_]+$/', (string) $heading) !== 1,
    ));

    expect($spelled(RankingRows::headers()))->toBe([])
        ->and($spelled($rows[0]))->toBe([])
        ->and($rows[1][13])->toBe('first_time: export.answers.no; needs_projector: export.answers.yes');
});
