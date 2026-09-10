<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomFieldType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Number = 'number';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Single line of text',
            self::Textarea => 'Paragraph',
            self::Select => 'Choose one from a list',
            self::Checkbox => 'Yes / no checkbox',
            self::Number => 'Number',
        };
    }

    public function needsOptions(): bool
    {
        return $this === self::Select;
    }
}
