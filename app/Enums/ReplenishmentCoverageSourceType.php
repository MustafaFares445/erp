<?php

declare(strict_types=1);

namespace App\Enums;

enum ReplenishmentCoverageSourceType: string
{
    case InternalTransfer = 'internal_transfer';
    case PurchaseOrderLine = 'purchase_order_line';
    case SupplierReplacement = 'supplier_replacement';
}
