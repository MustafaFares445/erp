<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use Filament\Support\Contracts\HasLabel;

enum ReplenishmentCoverageSourceType: string implements HasLabel
{
    use HasTranslatedLabel;

    case InternalTransfer = 'internal_transfer';
    case PurchaseOrderLine = 'purchase_order_line';
    case SupplierReplacement = 'supplier_replacement';
}
