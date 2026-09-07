<?php

declare(strict_types=1);

namespace App\Enums;

enum ReconciliationScope: string
{
    case InventoryLots = 'inventory_lots';
    case Receivables = 'receivables';
    case Payables = 'payables';
    case TaxRegister = 'tax_register';
}
