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
}
