<?php

declare(strict_types=1);

namespace App\Data\Inventory;

use App\Enums\TransferDiscrepancyDisposition;

/**
 * The actual transaction-UOM quantity received for one internal-transfer line.
 */
final readonly class TransferReceiptLine
{
    public function __construct(
        public int $operationLineId,
        public string $receivedTransactionQuantity,
        public ?TransferDiscrepancyDisposition $discrepancyDisposition = null,
        public ?string $discrepancyReason = null,
    ) {}
}
