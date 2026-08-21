<?php

declare(strict_types=1);

namespace App\Services\Purchasing\Exceptions;

use App\Models\PurchaseOrderLine;
use DomainException;

/**
 * Thrown when a receipt would take a line past its ordered quantity (V-08,
 * FR-040, FR-041).
 *
 * The owner chose hard blocking over a configurable tolerance (D7), so there is
 * no threshold to relax. The message names the variant and all three figures,
 * because "over-receipt" alone leaves a fifteen-line receipt with nothing to
 * act on — which is also why a database CHECK constraint was rejected as the
 * only mechanism: MySQL surfaces it as an opaque driver error.
 */
final class OverReceiptRejected extends DomainException
{
    public static function forLine(PurchaseOrderLine $line, float $incoming): self
    {
        return new self(__('admin.purchasing.errors.over_receipt', [
            'incoming' => mb_rtrim(mb_rtrim(number_format($incoming, 3, '.', ''), '0'), '.'),
            'variant' => $line->productVariant->sku,
            'ordered' => mb_rtrim(mb_rtrim((string) $line->quantity_ordered, '0'), '.'),
            'received' => mb_rtrim(mb_rtrim((string) $line->quantity_received, '0'), '.'),
        ]));
    }
}
