<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum LeadSource: string implements HasLabel
{
    use HasTranslatedLabel;

    case Website = 'website';
    case Referral = 'referral';
    case Exhibition = 'exhibition';
    case ColdCall = 'cold_call';
    case Campaign = 'campaign';
    case FieldObservation = 'field_observation';
    case Partner = 'partner';
    case Other = 'other';
}
