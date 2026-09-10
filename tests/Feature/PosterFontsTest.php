<?php

declare(strict_types=1);

use App\Support\Pdf\PosterFonts;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('ships the IBM Plex TTFs the poster needs', function () {
    expect(PosterFonts::files())->toHaveCount(3);

    foreach (PosterFonts::files() as $file) {
        expect(is_file($file['path']))->toBeTrue()
            ->and(filesize($file['path']))->toBeGreaterThan(100_000);
    }
});

it('registers those fonts with a dompdf instance', function () {
    // dompdf persists every registered family into font_dir/installed-fonts.json
    // and FontMetrics::getFont() keeps a process-wide static cache, so a test
    // that used the real storage/fonts would keep passing after register()
    // stopped working. Give this one an empty directory of its own.
    $fontDir = storage_path('framework/testing/fonts-'.Str::random(8));
    File::ensureDirectoryExists($fontDir);

    try {
        $dompdf = new Dompdf([
            'fontDir' => $fontDir,
            'fontCache' => $fontDir,
            'chroot' => [base_path()],
            'tempDir' => sys_get_temp_dir(),
        ]);

        expect($dompdf->getFontMetrics()->getFontFamilies())->not->toHaveKey('ibm plex sans');

        PosterFonts::register($dompdf);

        expect($dompdf->getFontMetrics()->getFontFamilies()['ibm plex sans'] ?? [])->toHaveKeys(['normal', 'bold'])
            ->and($dompdf->getFontMetrics()->getFont('IBM Plex Mono', 'bold'))->not->toBeNull();
    } finally {
        File::deleteDirectory($fontDir);
    }
});

it('sends guests on plain auth routes to the organizer login', function () {
    // Task 10 puts the conference asset downloads behind Laravel's own `auth`
    // middleware. The app has no route named "login", so bootstrap/app.php
    // must name the organizer panel's login page explicitly or
    // Authenticate::redirectTo() throws RouteNotFoundException. Probing a real
    // `auth` route is what makes this fail before Step 9: asserting only that
    // route('filament.organizer.auth.login') exists passes already.
    Route::middleware(['web', 'auth'])->get('/__auth-probe', fn (): string => 'ok');

    $this->get('/__auth-probe')->assertRedirect('/org/login');
});
