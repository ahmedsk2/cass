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
        preg_match_all(
            "/__\\(\\s*'((?:members|reviewer)(?:\\.[a-z0-9_]+)+)'/",
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
