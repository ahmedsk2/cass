<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The values match the strings stored in `conferences.presentation_types`
 * (Plan 2's CheckboxList options), so a conference offering only oral and
 * poster is filtered with a plain in_array against the backing values.
 */
enum PresentationPreference: string implements HasLabel
{
    case Oral = 'oral';
    case Poster = 'poster';
    case Either = 'either';

    public function getLabel(): string
    {
        return match ($this) {
            self::Oral => 'Oral presentation',
            self::Poster => 'Poster',
            self::Either => 'Either is fine',
        };
    }
}
