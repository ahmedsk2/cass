<?php

declare(strict_types=1);

use App\Support\Monitoring\SystemDiskSpace;
use Illuminate\Support\Str;

it('reads the free and total bytes of the filesystem holding a path', function () {
    $reading = (new SystemDiskSpace)->measure(storage_path());

    expect($reading)->toBeArray()->toHaveKeys(['free', 'total'])
        ->and($reading['total'])->toBeFloat()->toBeGreaterThan(0)
        ->and($reading['free'])->toBeFloat()->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual($reading['total']);
});

it('answers null, rather than throwing, for a path it cannot measure', function () {
    // Both functions warn on a path that does not exist, and Laravel's error
    // handler turns that warning into an ErrorException; cass:health has to
    // get a red row out of it, not a crash that prints no rows at all.
    expect((new SystemDiskSpace)->measure(base_path('no-such-directory-'.Str::random(12))))->toBeNull();
});
