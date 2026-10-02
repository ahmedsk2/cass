<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Until Plan 7 every panel loaded only Filament's precompiled stylesheet,
 * which holds fi-* component classes and no general utilities - so the Plan 1
 * dashboard banners' `rounded-xl border bg-amber-50 p-4` rendered as plain
 * text, and every later panel view fell back to inline styles. One theme, built
 * by Vite from Filament's own sources plus the panels' views, serves all three.
 */
it('gives all three panels the one vite-built theme', function (string $panel) {
    expect(Filament::getPanel($panel)->getViteTheme())->toBe('resources/css/filament/theme.css');
})->with(['admin', 'organizer', 'reviewer']);

it('links the compiled theme under the request nonce instead of the precompiled stylesheet', function (string $url) {
    expect(is_file(public_path('hot')))->toBeFalse('public/hot exists: stop `npm run dev` (composer run dev) before running the suite - the panels then link the dev server, not public/build.');

    $response = get($url)->assertOk();

    preg_match("/'nonce-([A-Za-z0-9]{40})'/", (string) $response->headers->get('Content-Security-Policy'), $matches);
    $nonce = $matches[1] ?? '';
    $html = (string) $response->getContent();

    // style-src is 'self' plus the nonce, so a same-origin <link> would load
    // either way; the nonce is Vite::useCspNonce() reaching the tag for free,
    // and asserting it pins that the theme came through Laravel's Vite.
    expect($nonce)->not->toBe('')
        ->and($html)->toMatch('#<link rel="stylesheet" href="[^"]*/build/assets/theme-[A-Za-z0-9_-]+\.css" nonce="'.$nonce.'"#')
        // Filament's default theme is replaced, not added to: two copies of
        // Filament's CSS on one page would be 600 KB loaded twice.
        ->and($html)->not->toContain('css/filament/filament/app.css');
})->with(['/admin/login', '/org/login', '/review/login']);

it('compiles every utility class the organizer dashboard uses into the theme', function () {
    // Read the build itself: this is what fails when the theme's @source
    // stops reaching resources/views/filament - the page would still render,
    // just unstyled, and no other test would notice.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $entry = $manifest['resources/css/filament/theme.css']['file'] ?? null;

    expect($entry)->not->toBeNull('The theme is not in public/build/manifest.json - run `npm run build`.');

    $css = (string) file_get_contents(public_path('build/'.$entry));
    $view = (string) file_get_contents(resource_path('views/filament/organizer/pages/dashboard.blade.php'));

    preg_match_all('/\bclass="([^"]+)"/', $view, $attributes);
    $classes = array_unique(preg_split('/\s+/', trim(implode(' ', $attributes[1]))) ?: []);
    $missing = [];

    foreach ($classes as $class) {
        // The selector as Tailwind writes it: `dark:bg-amber-950` is
        // `.dark\:bg-amber-950`, followed by a brace, a pseudo or a comma.
        $selector = '.'.addcslashes($class, ':/.[]%');

        if (! preg_match('/'.preg_quote($selector, '/').'(?=[{:,\s)])/', $css)) {
            $missing[] = $class;
        }
    }

    expect($classes)->not->toBe([])
        ->and($missing)->toBe([]);
});

it('styles the dashboard welcome card with theme classes rather than style attributes', function () {
    $organization = Organization::factory()->approved()->create(['name' => 'Alpha Society']);
    $owner = User::factory()->create();
    $organization->addMember($owner, OrganizationRole::Owner);

    actingAs($owner)->get("/org/{$organization->slug}")
        ->assertOk()
        ->assertSee('<p class="font-semibold">', escape: false)
        ->assertDontSee('style="font-weight:600"', escape: false);

    // The view itself carries no style attribute at all any more: the three
    // states (pending, suspended, welcome) are all utilities now.
    expect((string) file_get_contents(resource_path('views/filament/organizer/pages/dashboard.blade.php')))
        ->not->toContain('style=');
});

it('does not render one organization\'s dashboard for a member of another', function () {
    $mine = Organization::factory()->approved()->create();
    $theirs = Organization::factory()->create(['name' => 'Beta Society']);
    $user = User::factory()->create();
    $mine->addMember($user, OrganizationRole::Owner);

    // Theirs is pending, so its dashboard is the amber banner. A 404, not the
    // banner with another organization's name in it.
    actingAs($user)->get("/org/{$theirs->slug}")
        ->assertNotFound()
        ->assertDontSee('Beta Society');
});
