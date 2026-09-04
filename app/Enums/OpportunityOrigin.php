<?php

declare(strict_types=1);

namespace App\Enums;

enum OpportunityOrigin: string
{
    case AiVoiceNote = 'ai_voice_note';
    case Lead = 'lead';
    case ExistingCustomer = 'existing_customer';
    case FieldVisit = 'field_visit';
    case Inbound = 'inbound';
    case Manual = 'manual';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->headline()->toString();
    }
}
