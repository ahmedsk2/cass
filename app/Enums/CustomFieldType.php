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
            self::Text => __('enums.custom_field_type.text'),
            self::Textarea => __('enums.custom_field_type.textarea'),
            self::Select => __('enums.custom_field_type.select'),
            self::Checkbox => __('enums.custom_field_type.checkbox'),
            self::Number => __('enums.custom_field_type.number'),
        };
    }

    public function needsOptions(): bool
    {
        return $this === self::Select;
    }
}
