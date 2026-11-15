<?php

declare(strict_types=1);

use App\Actions\Conferences\GenerateConferencePoster;
use App\Actions\Conferences\GenerateConferenceQr;
use App\Enums\PosterSize;
use App\Models\Conference;
use App\Models\Organization;
use App\Models\ShortLink;
use chillerlan\QRCode\Common\GDLuminanceSource;
use chillerlan\QRCode\Common\Mode;
use chillerlan\QRCode\Data\Byte;
use chillerlan\QRCode\Decoder\Binarizer;
use chillerlan\QRCode\Decoder\ReedSolomonDecoder;
use chillerlan\QRCode\Detector\GridSampler;
use chillerlan\QRCode\Detector\PerspectiveTransform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

/**
 * Reads a rendered QR PNG back to its text: binarise the pixels, sample one
 * bit per module, then read the format and version information, correct with
 * Reed-Solomon and decode the byte segment, all with chillerlan's own classes.
 *
 * It skips only chillerlan's finder-pattern search (QRCode::readFromBlob()),
 * which locates the symbol the way a camera frame needs and misreads some
 * valid matrices at every scale, about one random short link in 330. A flat,
 * axis-aligned render does not need that search: the symbol is the bounding
 * box of the dark pixels, and the top-left finder's top edge is seven modules
 * wide, which gives the module size and so the dimension.
 */
function decodeQrPng(string $png): string
{
    $image = (new Binarizer(GDLuminanceSource::fromBlob($png)))->getBlackMatrix();
    $size = $image->getSize();

    $left = $top = $size;
    $right = $bottom = -1;

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            if ($image->check($x, $y)) {
                $left = min($left, $x);
                $right = max($right, $x);
                $top = min($top, $y);
                $bottom = max($bottom, $y);
            }
        }
    }

    $finderEdge = 0;

    while ($image->check($left + $finderEdge, $top)) {
        $finderEdge++;
    }

    $dimension = (int) round(($right - $left + 1) / ($finderEdge / 7));

    $transform = (new PerspectiveTransform)->quadrilateralToQuadrilateral(
        0, 0, $dimension, 0, $dimension, $dimension, 0, $dimension,
        $left, $top, $right + 1, $top, $right + 1, $bottom + 1, $left, $bottom + 1,
    );
    $modules = (new GridSampler)->sampleGrid($image, $dimension, $transform);

    $codewords = $modules->readCodewords();
    $version = $modules->getVersion();
    $eccLevel = $modules->getEccLevel();

    if ($version === null || $eccLevel === null || $modules->getMaskPattern() === null) {
        throw new RuntimeException('The PNG carries no readable format or version information.');
    }

    $stream = (new ReedSolomonDecoder($version, $eccLevel))->decode($codewords);

    if ($stream->read(4) !== Mode::BYTE) {
        throw new RuntimeException('The QR code does not start with a byte segment.');
    }

    $text = Byte::decodeSegment($stream, $version->getVersionNumber());

    if ($stream->available() >= 4 && $stream->read(4) !== Mode::TERMINATOR) {
        throw new RuntimeException('The QR code carries more than the one byte segment.');
    }

    return $text;
}

function publishedConferenceWithLink(): Conference
{
    $conference = Conference::factory()
        ->for(Organization::factory()->approved())
        ->published()
        ->create(['name' => 'Gulf Pediatric Critical Care 2026']);

    ShortLink::forTarget($conference);

    return $conference->fresh() ?? $conference;
}

it('renders an svg that encodes the short link', function () {
    $conference = publishedConferenceWithLink();

    $svg = app(GenerateConferenceQr::class)->svg($conference);

    // The fill attributes are the whole point: chillerlan draws the light
    // modules and the quiet zone too, so an SVG without them is a solid black
    // square that no phone can read - and every other assertion here still
    // passes.
    expect($svg)->toStartWith('<?xml')
        ->and($svg)->toContain('<svg')
        ->and($svg)->toContain('viewBox')
        ->and($svg)->toContain('fill="#000"')
        ->and($svg)->toContain('fill="#fff"')
        ->and($svg)->not->toContain('base64');
});

it('renders a png of at least the configured size', function () {
    $conference = publishedConferenceWithLink();

    $png = app(GenerateConferenceQr::class)->png($conference, 1024);
    $size = getimagesizefromstring($png);

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and($size)->not->toBeFalse()
        ->and($size[0])->toBeGreaterThanOrEqual(1024)
        ->and($size[0])->toBe($size[1]);
});

it('encodes exactly the short link in the png', function () {
    // Decode what a phone would decode: without this, encoding the long
    // conference URL (or the wrong conference) would pass every other test.
    //
    // 256 px, not the 1024 px the other tests render: chillerlan's binariser
    // turns every pixel into a PHP array element, so reading a 1036 px code
    // allocates about 68 MB on top of the booted framework and fatals on a
    // 128 M memory_limit. The payload is the same matrix at either scale, so
    // the small render proves exactly the same thing.
    $conference = publishedConferenceWithLink();

    $png = app(GenerateConferenceQr::class)->png($conference, 256);

    expect(decodeQrPng($png))->toBe($conference->shortLink?->url());
});

it('decodes a png whose data modules defeat a finder-pattern search', function () {
    // This short code under this root URL yields a matrix that chillerlan's
    // camera-style detector misreads at every scale (it locks onto data
    // modules that look like a finder pattern), although the symbol is valid.
    URL::forceRootUrl('http://localhost');
    ShortLink::generateCodesUsing(fn (): string => '4C58TPBN');

    try {
        $conference = publishedConferenceWithLink();
    } finally {
        ShortLink::generateCodesUsing(null);
    }

    $png = app(GenerateConferenceQr::class)->png($conference, 256);

    expect(decodeQrPng($png))->toBe('http://localhost/q/4C58TPBN')
        ->and($conference->shortLink?->url())->toBe('http://localhost/q/4C58TPBN');
});

it('encodes the absolute short link url', function () {
    $conference = publishedConferenceWithLink();

    expect(app(GenerateConferenceQr::class)->url($conference))
        ->toBe($conference->shortLink?->url())
        ->toStartWith(config('app.url'));
});

it('refuses to render a qr for a conference with no short link', function () {
    $conference = Conference::factory()->create();

    expect(fn () => app(GenerateConferenceQr::class)->svg($conference))
        ->toThrow(RuntimeException::class);
});

it('renders an a4 and an a3 poster pdf carrying the logo and the deadline', function () {
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();
    $path = UploadedFile::fake()->image('logo.png', 400, 200)->store('logos', 'branding');
    $conference->organization->forceFill(['logo_path' => $path])->save();

    foreach ([PosterSize::A4, PosterSize::A3] as $size) {
        $pdf = app(GenerateConferencePoster::class)->handle($conference->fresh() ?? $conference, $size);

        expect(substr($pdf, 0, 5))->toBe('%PDF-')
            ->and(strlen($pdf))->toBeGreaterThan(5_000);
    }
});

it('renders a poster for an organization that has no logo', function () {
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();

    $pdf = app(GenerateConferencePoster::class)->handle($conference, PosterSize::A4);

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});

it('puts the logo, name, call to action, short url and deadline on the poster', function () {
    // A PDF header and a byte count say nothing about the content, so capture
    // what the template was actually given and render it as HTML (spec 5.7
    // names each of these).
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();
    $path = UploadedFile::fake()->image('logo.png', 400, 200)->store('logos', 'branding');
    $conference->organization->forceFill(['logo_path' => $path])->save();
    $conference = $conference->fresh() ?? $conference;

    $data = null;
    View::composer('pdf.conference-poster', function ($view) use (&$data): void {
        $data = $view->getData();
    });

    app(GenerateConferencePoster::class)->handle($conference, PosterSize::A4);
    $html = view('pdf.conference-poster', $data)->render();

    expect($data['logoDataUri'])->toStartWith('data:image/jpeg;base64,')
        ->and($html)->toContain($conference->name)
        ->and($html)->toContain('Submit your abstract')
        ->and($html)->toContain((string) $conference->shortLink?->url())
        ->and($html)->toContain((string) $conference->deadlineInConferenceTimezone()?->format('j F Y, H:i'))
        ->and($html)->toContain('IBM Plex Mono');
});

it('normalises a large transparent logo before dompdf sees it', function () {
    // dompdf has no imagick here, so every PNG with an alpha channel goes
    // through CPDF::addImagePngAlpha(), which decodes at full size with GD and
    // loops over every pixel in PHP (about 0.8 s and tens of MB per megapixel).
    // A 2 MB flat-colour logo can be many megapixels, which is a 500 on the
    // 256 MB php-fpm worker every tenant shares.
    Storage::fake('branding');
    $conference = publishedConferenceWithLink();

    // 1600x800 is enough to prove the downscale; GD needs about 8 bytes per
    // pixel to decode a PNG, so a 4000x2000 fixture would exhaust the local
    // 128M memory_limit inside the test itself.
    $image = imagecreatetruecolor(1600, 800);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    unset($image);

    Storage::disk('branding')->put('logos/big.png', $png);
    $conference->organization->forceFill(['logo_path' => 'logos/big.png'])->save();

    $uri = (string) app(GenerateConferencePoster::class)->logoDataUri($conference->fresh() ?? $conference);
    $size = getimagesizefromstring((string) base64_decode(substr($uri, strlen('data:image/jpeg;base64,'))));

    // IHDR colour type 6 = RGBA, the case that triggers the slow path.
    expect(ord($png[25]))->toBe(6)
        ->and($uri)->toStartWith('data:image/jpeg;base64,')
        ->and($size[0] ?? 0)->toBe(660)
        ->and($size[1] ?? 0)->toBe(330);
});

it('names the download files after the conference slug', function () {
    $conference = publishedConferenceWithLink();

    expect(app(GenerateConferenceQr::class)->fileName($conference, 'svg'))
        ->toBe('gulf-pediatric-critical-care-2026-qr.svg')
        ->and(app(GenerateConferencePoster::class)->fileName($conference, PosterSize::A3))
        ->toBe('gulf-pediatric-critical-care-2026-poster-a3.pdf');
});
