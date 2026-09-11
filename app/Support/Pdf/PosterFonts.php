<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Dompdf\Dompdf;

final class PosterFonts
{
    public const FAMILY = 'IBM Plex Sans';

    public const MONO_FAMILY = 'IBM Plex Mono';

    /**
     * The three faces the poster template uses, with the dompdf style keys
     * FontMetrics::registerFont() expects. The Mono face is registered as
     * `bold` because the poster's `.short-url` rule is `font-weight: bold`,
     * and dompdf does not fall back to another weight inside a named family:
     * FontMetrics::getFont() returns null when the family has no entry for the
     * requested subtype, and the next family in the CSS stack is used instead.
     *
     * @return list<array{family: string, weight: string, style: string, path: string}>
     */
    public static function files(): array
    {
        return [
            [
                'family' => self::FAMILY,
                'weight' => 'normal',
                'style' => 'normal',
                'path' => base_path('resources/fonts/IBMPlexSans-Regular.ttf'),
            ],
            [
                'family' => self::FAMILY,
                'weight' => 'bold',
                'style' => 'normal',
                'path' => base_path('resources/fonts/IBMPlexSans-SemiBold.ttf'),
            ],
            [
                'family' => self::MONO_FAMILY,
                'weight' => 'bold',
                'style' => 'normal',
                'path' => base_path('resources/fonts/IBMPlexMono-SemiBold.ttf'),
            ],
        ];
    }

    /**
     * dompdf caches the parsed metrics in options.font_dir, so this is cheap
     * after the first render on a given machine.
     */
    public static function register(Dompdf $dompdf): void
    {
        if (! is_dir($directory = storage_path('fonts'))) {
            mkdir($directory, 0o775, recursive: true);
        }

        $metrics = $dompdf->getFontMetrics();

        foreach (self::files() as $font) {
            $metrics->registerFont(
                ['family' => $font['family'], 'weight' => $font['weight'], 'style' => $font['style']],
                $font['path'],
            );
        }
    }
}
