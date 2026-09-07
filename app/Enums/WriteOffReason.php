<?php

declare(strict_types=1);

namespace App\Enums;

enum WriteOffReason: string
{
    case Insolvency = 'insolvency';
    case Untraceable = 'untraceable';
    case DisputedAndAbandoned = 'disputed_and_abandoned';
    case TimeBarred = 'time_barred';
    case CommerciallyUneconomic = 'commercially_uneconomic';
    case Other = 'other';

    public function label(): string
    {
        return __('admin.accounting.write_off_reason.'.$this->value);
    }
}
