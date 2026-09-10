<?php

declare(strict_types=1);

namespace App\Support\Branding;

final class Contrast
{
    public static function ratio(string $hexA, string $hexB): float
    {
        $la = self::luminance($hexA);
        $lb = self::luminance($hexB);
        [$light, $dark] = $la >= $lb ? [$la, $lb] : [$lb, $la];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public static function passesAA(string $foreground, string $background): bool
    {
        return self::ratio($foreground, $background) >= 4.5;
    }

    public static function textOn(string $background): string
    {
        return self::ratio('#FFFFFF', $background) >= self::ratio('#111827', $background) ? '#FFFFFF' : '#111827';
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $channels = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
