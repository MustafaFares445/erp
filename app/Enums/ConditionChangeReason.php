<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum ConditionChangeReason: string implements HasLabel
{
    use HasTranslatedLabel;

    case QualityInspectionPassed = 'quality_inspection_passed';
    case QualityInspectionFailed = 'quality_inspection_failed';
    case SupplierDefect = 'supplier_defect';
    case ExpiredOnArrival = 'expired_on_arrival';
    case DamagedInTransit = 'damaged_in_transit';
    case CustomerReturnInspection = 'customer_return_inspection';
    case Other = 'other';
}
