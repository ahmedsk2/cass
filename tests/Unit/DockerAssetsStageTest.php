<?php

declare(strict_types=1);

/**
 * The production image compiles its stylesheets in the Dockerfile's `assets`
 * stage, which sees only what that stage COPYs. Tailwind does not fail when a
 * directory named by an @source line is missing - it scans nothing there and
 * builds green - so a stylesheet that reads a directory the stage never copied
 * ships without every class used only in it, in production and nowhere else.
 * Plan 6 found app/Livewire in exactly that state (backlog, "The production
 * image builds its CSS without app/Livewire in scope").
 *
 * These cases read the Dockerfile and the stylesheets as text, so they run in
 * the ordinary suite with no Docker. The CI `smoke` job is what proves the
 * image itself.
 */

/**
 * Every stage of the Dockerfile in order, as its name => its instructions,
 * with `\` continuations joined and comments dropped.
 *
 * @return array<string, list<string>>
 */
function dockerfileStages(): array
{
    $source = (string) preg_replace('/\\\\\R\s*/', ' ', (string) file_get_contents(base_path('Dockerfile')));
    $stages = [];
    $current = null;

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^FROM\s+\S+\s+AS\s+(\S+)$/i', $line, $match)) {
            $current = $match[1];
            $stages[$current] = [];

            continue;
        }

        if ($current !== null) {
            $stages[$current][] = $line;
        }
    }

    return $stages;
}

/** `resources/css/../../app/Livewire` => `app/Livewire`. */
function repositoryPath(string $path): string
{
    $segments = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..') {
            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    return implode('/', $segments);
}

/**
 * The stylesheets in vite.config.js's `input` - the files `npm run build`
 * compiles.
 *
 * @return list<string>
 */
function viteStylesheets(): array
{
    preg_match('/\binput\s*:\s*\[([^\]]*)\]/', (string) file_get_contents(base_path('vite.config.js')), $input);
    preg_match_all("/['\"]([^'\"]+\.css)['\"]/", $input[1] ?? '', $stylesheets);

    return $stylesheets[1];
}

/**
 * What the assets stage has under its WORKDIR when `npm run build` runs: one
 * entry per single-source COPY before that RUN, as destination => origin
 * ('context', or the stage it was copied from).
 *
 * @return array<string, string>
 */
function assetsStageInputs(): array
{
    $inputs = [];

    foreach (dockerfileStages()['assets'] ?? [] as $instruction) {
        if (preg_match('/^RUN\s+npm run build\b/', $instruction)) {
            break;
        }

        if (! preg_match('/^COPY\s+(.+)$/i', $instruction, $match)) {
            continue;
        }

        $origin = 'context';
        $paths = [];

        foreach (preg_split('/\s+/', $match[1]) ?: [] as $word) {
            if (preg_match('/^--from=(\S+)$/', $word, $from)) {
                $origin = $from[1];
            } elseif (! str_starts_with($word, '--')) {
                $paths[] = $word;
            }
        }

        // `COPY package*.json vite.config.js ./` drops files into the WORKDIR
        // and reads no directory a stylesheet could; only `COPY <one> <dest>`
        // puts a tree on a path.
        if (count($paths) === 2) {
            $inputs[repositoryPath($paths[1])] = $origin;
        }
    }

    return $inputs;
}

/**
 * Every repository path a compiled stylesheet reads outside node_modules: the
 * relative @import targets and the @source roots, resolved against the
 * stylesheet's own directory.
 *
 * @return array<string, string> path => the stylesheet that reads it
 */
function stylesheetReads(): array
{
    $reads = [];

    foreach (viteStylesheets() as $stylesheet) {
        preg_match_all("/@(?:import|source)\s+['\"](\.[^'\"]+)['\"]/", (string) file_get_contents(base_path($stylesheet)), $paths);

        foreach ($paths[1] as $path) {
            // Up to the first glob: '../../../app/Filament/**/*' reads the
            // directory app/Filament.
            $path = (string) preg_replace('#/?[*{].*$#', '', $path);

            $reads[repositoryPath(dirname($stylesheet).'/'.$path)] = $stylesheet;
        }
    }

    return $reads;
}

it('builds the vendor stage before the assets stage', function () {
    $order = array_keys(dockerfileStages());

    // COPY --from=<name> can only name an EARLIER stage. BuildKit refuses the
    // other order outright: 'cannot copy from stage "vendor", it needs to be
    // defined before current stage "assets"'.
    expect($order)->toContain('vendor')
        ->and($order)->toContain('assets')
        ->and(array_search('vendor', $order, true))->toBeLessThan(array_search('assets', $order, true));
});

it('takes vendor/filament into the assets stage from the vendor stage', function () {
    // Not from the context: .dockerignore excludes vendor, so a context COPY
    // of it fails with '"/vendor/filament": not found' - and the panel theme
    // @imports Filament's own CSS out of it.
    expect(assetsStageInputs())->toHaveKey('vendor/filament')
        ->and(assetsStageInputs()['vendor/filament'] ?? null)->toBe('vendor')
        ->and((string) file_get_contents(base_path('.dockerignore')))->toMatch('/^vendor$/m');
});

it('copies every directory a stylesheet reads into the assets stage before it builds', function () {
    $inputs = assetsStageInputs();
    $reads = stylesheetReads();
    $missing = [];

    expect($reads)->not->toBe([]);

    foreach ($reads as $path => $stylesheet) {
        $covered = false;

        foreach (array_keys($inputs) as $copied) {
            if ($path === $copied || str_starts_with($path, $copied.'/')) {
                $covered = true;

                break;
            }
        }

        if (! $covered) {
            $missing[] = "{$path} (read by {$stylesheet})";
        }
    }

    expect($missing)->toBe([]);
});

it('scans only the sources a stylesheet names, so a checkout and the image build the same file', function () {
    // Without source(none), Tailwind's automatic detection scans from the
    // project root: the whole repository on a checkout, and only what the
    // assets stage copied in the image - so the case above would be blind to
    // every directory nobody wrote an @source line for. The panel theme gets
    // source(none) from Filament's own theme.css, which it @imports.
    $automatic = [];

    foreach (viteStylesheets() as $stylesheet) {
        $css = (string) file_get_contents(base_path($stylesheet));

        preg_match_all("/@import\s+['\"](\.[^'\"]+\.css)['\"]/", $css, $imports);

        foreach ($imports[1] as $import) {
            // "\n" first: a stylesheet saved without a final newline must not
            // glue the import's first line onto its own last one.
            $css .= "\n".(string) file_get_contents(base_path(repositoryPath(dirname($stylesheet).'/'.$import)));
        }

        // ^ with /m: a line that starts with @import, never the docblock's
        // ` * ... @import 'tailwindcss' source(none)` prose.
        if (! preg_match("/^@import\s+['\"]tailwindcss['\"]\s+source\(none\)/m", $css)) {
            $automatic[] = $stylesheet;
        }
    }

    expect(viteStylesheets())->not->toBe([])
        ->and($automatic)->toBe([]);
});
