<?php

declare(strict_types=1);

namespace App\Actions\Conferences;

use App\Models\Conference;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

class GenerateConferenceQr
{
    /**
     * The QR encodes the short link, never the long conference URL: it keeps
     * the code sparse enough to survive a phone camera at poster distance, and
     * it means every scan is counted.
     */
    public function url(Conference $conference): string
    {
        $shortLink = $conference->shortLink;

        if ($shortLink === null) {
            throw new RuntimeException('This conference has no short link yet. Publish it first.');
        }

        return $shortLink->url();
    }

    /**
     * `svgUseFillAttributes` stays at its default (true): with it false every
     * layer is a `<path class="..." d="..."/>` with no fill and no `<style>`,
     * and because `drawLightModules` also defaults to true the light modules
     * and the quiet zone would be painted black by SVG's default fill - a
     * solid black square no phone can read. This file is the print master.
     */
    public function svg(Conference $conference): string
    {
        /** @var string $svg */
        $svg = (new QRCode($this->options(QROutputInterface::MARKUP_SVG, ['cssClass' => 'cass-qr'])))
            ->render($this->url($conference));

        return $svg;
    }

    /**
     * chillerlan renders whole modules only, so the result is the smallest
     * whole-module square that reaches $minSize (typically 1025-1100 px for a
     * 1024 px request). Resampling to exactly 1024 would blur the modules for
     * no benefit, and print drivers scale vectors from the SVG anyway.
     *
     * The matrix is built once and re-rendered by a second QRCode instance at
     * the computed scale; `getQRMatrix()` has already added the quiet zone.
     */
    public function png(Conference $conference, ?int $minSize = null): string
    {
        $minSize ??= (int) config('cass.qr.png_min_size');

        $matrix = (new QRCode($this->options(QROutputInterface::GDIMAGE_PNG)))
            ->addByteSegment($this->url($conference))
            ->getQRMatrix();

        $scale = max(1, (int) ceil($minSize / $matrix->getSize()));

        /** @var string $png */
        $png = (new QRCode($this->options(QROutputInterface::GDIMAGE_PNG, ['scale' => $scale])))
            ->renderMatrix($matrix);

        return $png;
    }

    public function fileName(Conference $conference, string $extension): string
    {
        return "{$conference->slug}-qr.{$extension}";
    }

    /**
     * Every setting goes in the constructor array. QROptions properties are
     * protected and reachable only through SettingsContainerAbstract::__set,
     * so assigning one afterwards works at runtime but fails Larastan level 6
     * with property.protected (fact 2).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function options(string $outputType, array $overrides = []): QROptions
    {
        return new QROptions([
            'outputType' => $outputType,
            // Default true in v5: it would return a data: URI instead of bytes.
            'outputBase64' => false,
            // M survives a printed poster with a fingerprint on it and still
            // keeps a 37-character URL inside a small version.
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
            'scale' => 10,
            'imageTransparent' => false,
            ...$overrides,
        ]);
    }
}
