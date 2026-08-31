<?php

declare(strict_types=1);

namespace App\Data\Inventory;

/**
 * The receiving count for every internal-transfer line that remains in transit.
 */
final readonly class TransferReceiptCommand
{
    /**
     * @param  list<TransferReceiptLine>  $lines
     */
    public function __construct(public array $lines) {}
}
