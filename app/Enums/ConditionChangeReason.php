<?php

declare(strict_types=1);

namespace App\Enums;

enum ConditionChangeReason: string
{
    case QualityInspectionPassed = 'quality_inspection_passed';
    case QualityInspectionFailed = 'quality_inspection_failed';
    case SupplierDefect = 'supplier_defect';
    case ExpiredOnArrival = 'expired_on_arrival';
    case DamagedInTransit = 'damaged_in_transit';
    case CustomerReturnInspection = 'customer_return_inspection';
    case Other = 'other';
}
