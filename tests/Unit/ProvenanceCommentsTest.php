<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * CLAUDE.md: comments explain the code, never its provenance. A comment that names a plan, a task or
 * a numbered fact points into a document the reader may not have; it should state the fact itself.
 *
 * The references written before that rule are counted rather than rewritten, so the count may fall
 * but never rise. Fixing one lowers the count, and the baseline then comes down with it.
 */
const PROVENANCE_COMMENT_BASELINE = 365;

it('adds no comment that names a plan, a task or a fact', function () {
    $pattern = '/\b(?:Plan|Task|[Ff]act) \d+/';
    $count = 0;

    $files = (new Finder)->files()->name('*.php')->in([
        base_path('app'),
        base_path('config'),
        base_path('database'),
        base_path('lang'),
        base_path('resources/views'),
        base_path('routes'),
        base_path('tests'),
    ]);

    $paths = [base_path('bootstrap/app.php'), base_path('bootstrap/providers.php')];

    foreach ($files as $file) {
        $paths[] = $file->getRealPath();
    }

    foreach ($paths as $path) {
        if ($path === __FILE__) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (str_ends_with($path, '.blade.php')) {
            preg_match_all('/\{\{--.*?--\}\}/s', $source, $blocks);

            foreach ($blocks[0] as $block) {
                $count += preg_match_all($pattern, $block);
            }

            continue;
        }

        foreach (PhpToken::tokenize($source) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                $count += preg_match_all($pattern, $token->text);
            }
        }
    }

    expect($count)->toBeLessThanOrEqual(
        PROVENANCE_COMMENT_BASELINE,
        "{$count} comment references to a plan, task or fact, above the baseline of ".PROVENANCE_COMMENT_BASELINE.': a new comment names one; state the fact instead (CLAUDE.md).',
    );
});
