<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\StockTransfer;

/**
 * Workflow status of a {@see StockTransfer} (FI-4).
 *
 * A transfer remains immutable after it has been dispatched. Destination
 * stock is increased only when the dispatched document is received.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case Received = 'received';
}
