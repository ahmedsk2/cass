<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

use function Pest\Laravel\artisan;

beforeEach(function () {
    // Under test FileUploadConfiguration::disk() is `tmp-for-tests`, not
    // `local`, and Livewire fakes it once per PROCESS
    // (FileUploadConfiguration::storage()) - so a file left by one test would
    // still be there in the next. A fresh fake per test, and a frozen clock so
    // "a day old" means the same instant to the test and to the sweep.
    Storage::fake(FileUploadConfiguration::disk());
    $this->freezeTime();
});

/**
 * One temporary upload as FileUploadConfiguration::storeTemporaryFile() leaves
 * it - the file and its `.json` sidecar - last modified $minutes ago.
 */
function temporaryUpload(string $name, int $minutes): void
{
    $disk = Storage::disk(FileUploadConfiguration::disk());

    foreach ([$name, $name.'.json'] as $file) {
        $path = FileUploadConfiguration::path($file);
        $disk->put($path, 'x');
        touch($disk->path($path), now()->subMinutes($minutes)->getTimestamp());
    }
}

it('deletes temporary uploads more than a day old and keeps the younger ones', function () {
    temporaryUpload('abandoned.pdf', 24 * 60 + 1);
    temporaryUpload('in-progress.pdf', 24 * 60 - 1);

    artisan('cass:sweep-uploads')
        ->expectsOutputToContain('Deleted 2 temporary upload file(s)')
        ->assertExitCode(0);

    expect(Storage::disk(FileUploadConfiguration::disk())->allFiles(FileUploadConfiguration::path()))
        ->toEqualCanonicalizing([
            FileUploadConfiguration::path('in-progress.pdf'),
            FileUploadConfiguration::path('in-progress.pdf.json'),
        ]);
});

it('never touches a file outside the temporary upload directory', function () {
    // In production the temporary directory and every stored abstract share
    // one disk (`local`, storage/app/private), so the sweep must be scoped to
    // the directory and not to the disk. A year-old submission file is the
    // case that would hurt.
    $disk = Storage::disk(FileUploadConfiguration::disk());
    $disk->put('3f/01JBX5Q4ZQ7K8M2N3P4R5S6T7V.pdf', 'x');
    touch($disk->path('3f/01JBX5Q4ZQ7K8M2N3P4R5S6T7V.pdf'), now()->subYear()->getTimestamp());

    artisan('cass:sweep-uploads')->assertExitCode(0);

    expect($disk->exists('3f/01JBX5Q4ZQ7K8M2N3P4R5S6T7V.pdf'))->toBeTrue();
});

it('is scheduled every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'cass:sweep-uploads'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 * * * *');
});
