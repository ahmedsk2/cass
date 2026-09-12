<?php

declare(strict_types=1);

use App\Enums\ConferenceStatus;
use App\Models\Conference;
use App\Models\Organization;

/**
 * The CSP, in a real browser.
 *
 * Note what this does NOT use: assertNoConsoleLogs(). The plugin collects
 * console output by hooking console.* from an init script
 * (Pest\Browser\Playwright\Page::consoleLogs() reads
 * window.__pestBrowser.consoleLogs), and a CSP violation is reported by the
 * browser itself rather than through console.log - so that assertion would
 * pass on a page where every script was blocked.
 *
 * Instead each case PROBES the policy: it appends an un-nonced inline <script>
 * to the DOM and asserts it did not run (a DOM-inserted script IS subject to
 * CSP, unlike Playwright's own evaluate), and then asserts that the things the
 * page needs are there - which is what fails if the nonce is wrong.
 *
 * Every case here relies on phpunit.browser.xml's APP_URL being
 * http://127.0.0.1 (Task 3 Step 4): the plugin serves the application from
 * that host, the landing route is host-constrained to APP_URL's host, and
 * ResolveCustomDomain 404s anything else. A 404 here is that, not a CSP
 * failure - the browser run at the end of Task 3 is what separates them.
 *
 * The signed-in panel page that would exercise the third published view
 * (filament-panels::livewire.sidebar) is NOT here. pest-plugin-browser 5.0.1
 * has no actingAs(), and a form login would depend on the array session driver
 * phpunit.browser.xml pins and on Filament's own login markup - neither of
 * which this file is about. The sidebar's nonce is pinned instead by
 * `nonces the sidebar script filament renders on every authenticated panel page`
 * in tests/Feature/Public/SecurityHeadersTest.php, which asserts the same tag
 * on the same page through a real request.
 */
$probe = <<<'JS'
(() => {
    // No securitypolicyviolation listener: the browser fires that event from a
    // queued task, not synchronously from appendChild, so anything read back
    // inside this IIFE is always empty and would assert nothing. The injected
    // script below is the real probe - a DOM-inserted inline script IS subject
    // to CSP, unlike Playwright's own evaluate().
    const injected = document.createElement('script');
    injected.textContent = 'window.__cspInlineRan = true;';
    document.body.appendChild(injected);

    return {
        inlineRan: window.__cspInlineRan === true,
        hasLivewire: typeof window.Livewire !== 'undefined',
        hasAlpine: typeof window.Alpine !== 'undefined',
        // A BARE identifier, not window.loadDarkMode. Filament declares it as
        // `const loadDarkMode = () => {...}` at the top level of a classic
        // script, and a top-level const is a binding in the global LEXICAL
        // environment rather than a property of window - so window.loadDarkMode
        // is undefined even on a page where that script ran perfectly.
        // `typeof` on an unresolvable identifier is the one reference that does
        // not throw, so this reads 'undefined' rather than exploding when the
        // CSP really did block the script, which is the case being probed.
        hasDarkMode: typeof loadDarkMode === 'function',
        bodyBackground: getComputedStyle(document.body).backgroundColor,
    };
})()
JS;

it('refuses an injected inline script on the landing page, and still loads its stylesheet', function () use ($probe) {
    $result = visit('/')->assertSee('CASS')->script($probe);

    // The whole point of a nonce: an inline script the application did not
    // write does not run.
    expect($result['inlineRan'])->toBeFalse()
        // And style-src 'self' still let the Vite bundle through, so the page
        // is not unstyled - which is what a wrong style-src looks like.
        ->and($result['bodyBackground'])->not->toBe('rgba(0, 0, 0, 0)');
})->group('browser');

it('runs livewire and alpine on the submission form under the policy', function () use ($probe) {
    $organization = Organization::factory()->approved()->create();
    $conference = Conference::factory()->for($organization)->create([
        'status' => ConferenceStatus::Open,
        'submission_opens_at' => now()->subWeek(),
        'submission_deadline' => now()->addWeek(),
    ]);

    $result = visit('/c/'.$organization->slug.'/'.$conference->slug.'/submit')->script($probe);

    // If the nonce on Livewire's script tag were wrong, this is where it shows:
    // window.Livewire would be undefined and the form would be inert.
    expect($result['hasLivewire'])->toBeTrue()
        ->and($result['hasAlpine'])->toBeTrue()
        ->and($result['inlineRan'])->toBeFalse();
})->group('browser');

it('renders a panel login with filament own inline scripts intact', function () use ($probe) {
    $result = visit('/org/login')->assertSee('CASS')->script($probe);

    // loadDarkMode is defined by the inline <script> in the published copy of
    // filament-panels::components.layout.base (the `@else` branch of the
    // dark-mode block, which is the one that renders: Filament's own default is
    // dark mode on and not forced, and no panel provider overrides it).
    // If that copy forgot its nonce, the CSP blocks the script and this is
    // undefined - which is the exact failure the drift test exists to make
    // loud.
    expect($result['hasDarkMode'])->toBeTrue()
        ->and($result['hasLivewire'])->toBeTrue()
        ->and($result['inlineRan'])->toBeFalse();
})->group('browser');
