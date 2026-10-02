<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_rfq_supplier_id', 'purchase_rfq_line_id', 'unit_price', 'offered_quantity', 'lead_time_days', 'minimum_order_quantity', 'notes'])]
final class PurchaseRfqResponseLine extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'offered_quantity' => 'decimal:6',
            'lead_time_days' => 'integer',
            'minimum_order_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<PurchaseRfqSupplier, $this> */
    public function rfqSupplier(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfqSupplier::class, 'purchase_rfq_supplier_id');
    }

    /** @return BelongsTo<PurchaseRfqLine, $this> */
    public function rfqLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfqLine::class, 'purchase_rfq_line_id');
    }
}
