<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryConditionChangeType: string
{
    case QuarantineDisposition = 'quarantine_disposition';
}
