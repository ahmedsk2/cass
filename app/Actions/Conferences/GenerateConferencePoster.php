<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Enums\PosterSize;
use App\Models\Conference;
use App\Support\Branding\OrganizationTheme;
use App\Support\Pdf\PosterFonts;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class GenerateConferencePoster
{
    public function __construct(private readonly GenerateConferenceQr $qr) {}

    /**
     * Returns the PDF bytes. Both images are inlined as data: URIs, so dompdf
     * reads nothing from disk except the two bundled TTFs, and the file is
     * self-contained when it is emailed to a print shop.
     */
    public function handle(Conference $conference, PosterSize $size): string
    {
        $pdf = Pdf::loadView('pdf.conference-poster', [
            'conference' => $conference,
            'organization' => $conference->organization,
            'theme' => OrganizationTheme::for($conference->organization),
            'size' => $size,
            'scale' => $size->typeScale(),
            'qrDataUri' => 'data:image/png;base64,'.base64_encode($this->qr->png($conference, 1200)),
            'logoDataUri' => $this->logoDataUri($conference),
            'shortUrl' => $this->qr->url($conference),
        ]);

        PosterFonts::register($pdf->getDomPDF());

        return $pdf->setPaper($size->paper(), 'portrait')->output();
    }

    public function fileName(Conference $conference, PosterSize $size): string
    {
        return "{$conference->slug}-poster-{$size->value}.pdf";
    }

    /**
     * Public so a test can assert what dompdf is handed.
     *
     * The upload rules accept any PNG or JPEG up to 2 MB with no dimension
     * limit, and the branding help text recommends a transparent PNG - but
     * dompdf's CPDF backend has no Imagick here, so every PNG with an alpha
     * channel goes through addImagePngAlpha(), which decodes at full size with
     * GD and then loops over every pixel in PHP (roughly 0.8 s and tens of MB
     * per megapixel). A flat-colour 6000x3500 logo fits inside 2 MB and would
     * blow the 256 MB php-fpm worker that every tenant's pages share.
     *
     * So the logo is normalised here instead: refused above 16 MP, scaled to
     * the height the template uses, flattened onto white and handed over as
     * JPEG, which dompdf embeds with addJpegFromFile and never decodes.
     */
    public function logoDataUri(Conference $conference): ?string
    {
        $path = $conference->organization->logo_path;

        if ($path === null) {
            return null;
        }

        $disk = Storage::disk('branding');

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = (string) $disk->get($path);
        $size = getimagesizefromstring($bytes);

        // 16 MP is already about 128 MB of GD buffers to decode; a logo that
        // big is a mistake, and the poster is better off without it than
        // returning a 500.
        if ($size === false || ($size[0] * $size[1]) > 16_000_000) {
            return null;
        }

        $source = imagecreatefromstring($bytes);

        if ($source === false) {
            return null;
        }

        $targetHeight = min(330, imagesy($source));
        $targetWidth = max(1, (int) round(imagesx($source) * $targetHeight / imagesy($source)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($source), imagesy($source));
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($canvas);

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
