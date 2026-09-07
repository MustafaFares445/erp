<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadDisqualificationReason: string
{
    case NoFit = 'no_fit';
    case NoBudget = 'no_budget';
    case NoResponse = 'no_response';
    case ExistingCustomer = 'existing_customer';
    case Duplicate = 'duplicate';
    case Timing = 'timing';
    case Other = 'other';
}
