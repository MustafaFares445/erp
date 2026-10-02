<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomFieldDataType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';

    public function label(): string
    {
        return str($this->value)->headline()->toString();
    }

    public function storageColumn(): string
    {
        return match ($this) {
            self::Text, self::LongText, self::Select => 'value_text',
            self::Number => 'value_number',
            self::Date => 'value_date',
            self::Boolean => 'value_boolean',
        };
    }
}
