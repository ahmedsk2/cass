<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * public/images is served to anyone who asks, and everything in it is licensed
 * for this project only (public/images/README.md). An image that no view,
 * stylesheet, script or class names is a licensed file published for nothing -
 * and badge.svg sat there from Plan 1 to Plan 7 because no test asked.
 *
 * A plain substring search, and deliberately so: every reference in this
 * application is a literal `asset('images/...')`, and an image that is ever
 * named only by a computed path should have to say so here.
 */
it('ships only images that something in resources or app refers to', function () {
    $sources = '';

    foreach ((new Finder)->files()->in([resource_path(), app_path()]) as $file) {
        $sources .= $file->getContents();
    }

    $unreferenced = [];

    foreach ((new Finder)->files()->in(public_path('images'))->notName('README.md') as $image) {
        $relative = 'images/'.str_replace('\\', '/', $image->getRelativePathname());

        if (! str_contains($sources, $relative)) {
            $unreferenced[] = $relative;
        }
    }

    expect($unreferenced)->toBe([]);
});
