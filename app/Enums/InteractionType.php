<?php

declare(strict_types=1);

namespace App\Enums;

enum InteractionType: string
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case FieldVisit = 'field_visit';
    case Demo = 'demo';
    case Note = 'note';

    public function label(): string
    {
        return __('admin.crm.interaction_type.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Call => 'info',
            self::Email => 'gray',
            self::Meeting => 'primary',
            self::FieldVisit => 'warning',
            self::Demo => 'success',
            self::Note => 'gray',
        };
    }
}
