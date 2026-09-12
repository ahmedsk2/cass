<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/*
 * The command's own output, which the runbook's "Importing the legacy
 * conferences" section tells an operator to read. The mapping itself is covered
 * exhaustively in tests/Unit/ImportLegacyTest.php; what is here is the two
 * things only the command decides - where the manual-review list comes out, and
 * the exit code.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->dump = base_path('tests/Fixtures/legacy/dump.sql');
    $this->uploads = sys_get_temp_dir().'/cass-legacy-uploads';

    if (! is_dir($this->uploads)) {
        mkdir($this->uploads, 0777, true);
    }

    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1699719268_4752.pdf');
    copy(base_path('tests/Fixtures/abstract.pdf'), $this->uploads.'/1699719578_3346.pdf');

    Organization::factory()->approved()->create([
        'name' => 'Example Society',
        'slug' => 'example-society',
    ]);
});

it('prints the manual-review list itself when a dry run writes no report file', function () {
    artisan('cass:import-legacy', [
        'sql' => $this->dump,
        'uploads-dir' => $this->uploads,
        '--organization-slug' => 'example-society',
        '--dry-run' => true,
    ])
        // "Read the report" with no report is the one instruction a rehearsal
        // must not give, so the list comes out on the terminal instead.
        ->expectsOutputToContain('import.invalid')
        // A rehearsal is not a failure, even though the list is never empty.
        ->assertExitCode(0);

    // And nothing is left on the cass-storage volume for somebody to tell
    // apart from the real run's report afterwards.
    expect(Storage::disk('local')->allFiles('legacy'))->toBe([]);
});

it('names the report file on a real run, and exits 1 because the list is not empty', function () {
    artisan('cass:import-legacy', [
        'sql' => $this->dump,
        'uploads-dir' => $this->uploads,
        '--organization-slug' => 'example-society',
    ])
        // An import nobody reads the report of is an import that wrote
        // guesses, so a non-empty list is a non-zero exit.
        ->assertExitCode(1);

    expect(Storage::disk('local')->allFiles('legacy'))->toHaveCount(1);
});
