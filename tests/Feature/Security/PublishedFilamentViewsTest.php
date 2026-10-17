<?php

declare(strict_types=1);

/**
 * THREE Filament Blade views are published into resources/views/vendor so that
 * every inline <script> and <style> they emit carries the CSP nonce. Filament
 * 5.10.2 nonces its inline scripts only when a nonce is set, and its inline
 * styles never; the copies put the same unconditional attribute on all of them.
 * Publishing freezes them against an upgrade, and this is the alarm.
 *
 * When a case here fails, the fix is NOT to update the hash. It is:
 *   1. diff the new vendor file against the published copy;
 *   2. re-publish it, re-adding the nonce attributes listed below (on a tag
 *      that carries Filament's own conditional nonce, ours takes its place);
 *   3. then update the hash here, in the same commit.
 */
it('has not drifted from the filament view it was published from', function (string $vendor, string $published, string $sha, array $nonced) {
    $vendorPath = base_path($vendor);
    $publishedPath = base_path($published);

    expect(file_exists($vendorPath))->toBeTrue("Missing {$vendor} - did Filament move it?")
        ->and(file_exists($publishedPath))->toBeTrue("Missing {$published} - the panel has no nonce without it.");

    expect(hash_file('sha256', $vendorPath))->toBe(
        $sha,
        "{$vendor} changed. Re-publish {$published} from it, re-add the nonce, then update this hash."
    );

    $copy = (string) file_get_contents($publishedPath);

    // Every tag that has to carry one, counted. A re-publish that forgets one
    // is a panel whose theme toggle silently stops working.
    foreach ($nonced as $needle => $expected) {
        expect(substr_count($copy, (string) $needle))->toBe($expected, (string) $needle);
    }
})->with([
    'filament::assets' => [
        'vendor/filament/support/resources/views/assets.blade.php',
        'resources/views/vendor/filament/assets.blade.php',
        'e2064e91c57f570b47e6a3e9877e32b6cec7e1b5f04b541eb99a4ecc87f290c8',
        // One inline <script> (window.filamentData) and one inline <style>.
        ['{{ $cspNonce }}' => 2],
    ],
    'filament-panels::layout.base' => [
        'vendor/filament/filament/resources/views/components/layout/base.blade.php',
        'resources/views/vendor/filament-panels/components/layout/base.blade.php',
        'fdce60785520b7265116d9891f83e5b8fd00a9154dd8b9ff06f0e236df895d52',
        // Two <style> and five <script>: [x-cloak], the :root variables, the
        // two theme setters, loadDarkMode()'s definition, the echo bootstrap
        // and the loadDarkMode() call.
        ['{{ $cspNonce }}' => 7],
    ],
    'filament-panels::livewire.sidebar' => [
        'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
        'resources/views/vendor/filament-panels/livewire/sidebar.blade.php',
        '4cf575d981f1af6a8347b7fe385ed10ea80acc3d8ef4c4be6ebc48ae52e9dcb9',
        // One inline <script>: the collapsedGroups localStorage block.
        ['{{ $cspNonce }}' => 1],
    ],
]);
