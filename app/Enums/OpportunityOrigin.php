<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OpportunityOrigin: string implements HasLabel
{
    case AiVoiceNote = 'ai_voice_note';
    case Lead = 'lead';
    case ExistingCustomer = 'existing_customer';
    case FieldVisit = 'field_visit';
    case Inbound = 'inbound';
    case Manual = 'manual';

    public function label(): string
    {
        return __(str($this->value)->replace('_', ' ')->headline()->toString());
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
