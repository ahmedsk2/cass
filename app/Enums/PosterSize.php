<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PosterSize: string implements HasLabel
{
    case A4 = 'a4';
    case A3 = 'a3';

    public function getLabel(): string
    {
        return match ($this) {
            self::A4 => 'A4 poster (210 x 297 mm)',
            self::A3 => 'A3 poster (297 x 420 mm)',
        };
    }

    /** dompdf paper name. */
    public function paper(): string
    {
        return $this->value;
    }

    /**
     * Point sizes for the poster type scale, so an A3 print is not just an A4
     * scaled by the printer driver.
     *
     * @return array{title: int, lead: int, body: int, qr: int}
     */
    public function typeScale(): array
    {
        return match ($this) {
            self::A4 => ['title' => 30, 'lead' => 16, 'body' => 11, 'qr' => 250],
            self::A3 => ['title' => 44, 'lead' => 23, 'body' => 15, 'qr' => 360],
        };
    }
}
