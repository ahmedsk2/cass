<?php

declare(strict_types=1);

/**
 * Filament 5.8.1 has no nonce support anywhere (Plan 6 fact 5), so THREE of its
 * Blade views are published into resources/views/vendor with a nonce added.
 * Publishing freezes them against an upgrade, and this is the alarm.
 *
 * When a case here fails, the fix is NOT to update the hash. It is:
 *   1. diff the new vendor file against the published copy;
 *   2. re-publish it, re-adding the nonce attributes listed below;
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
        '26bfb0666a21c4456d5ba16d5a4f34e5995b7d470737a0d4969ff95a6d9c8a83',
        // One inline <script> (window.filamentData) and one inline <style>.
        ['{{ $cspNonce }}' => 2],
    ],
    'filament-panels::layout.base' => [
        'vendor/filament/filament/resources/views/components/layout/base.blade.php',
        'resources/views/vendor/filament-panels/components/layout/base.blade.php',
        'b95a450ddaf68af8b5c71760bcb30a295ec71e7f11fcbd2fe9fc13f25ae26968',
        // Two <style> and five <script>: [x-cloak], the :root variables, the
        // two theme setters, loadDarkMode()'s definition, the echo bootstrap
        // and the loadDarkMode() call.
        ['{{ $cspNonce }}' => 7],
    ],
    'filament-panels::livewire.sidebar' => [
        'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
        'resources/views/vendor/filament-panels/livewire/sidebar.blade.php',
        '9d53c25551beb97b9642a04d34cd34a33805de3664bf8d17abbf1ad4b8017a6b',
        // One inline <script>: the collapsedGroups localStorage block.
        ['{{ $cspNonce }}' => 1],
    ],
]);
