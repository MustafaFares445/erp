<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InvoiceDeliveryLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery invoiced on one invoice (WP-2.13, GAP-MW-13).
 *
 * The unique index on `inventory_operation_id` is THE control that a delivery is invoiced at
 * most once, enforced across every invoice — consolidated or standalone — unlike the deprecated
 * `invoices.inventory_operation_id` convenience column it supersedes.
 */
#[Fillable(['invoice_id', 'inventory_operation_id'])]
final class InvoiceDeliveryLink extends Model
{
    /** @use HasFactory<InvoiceDeliveryLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }
}
