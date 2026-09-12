<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_debit_note_id',
    'inventory_return_line_id',
    'bill_line_id',
    'product_variant_id',
    'description',
    'quantity',
    'unit_price',
    'tax_amount',
    'line_total',
])]
final class SupplierDebitNoteLine extends Model
{
    #[\Override]
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SupplierDebitNote, $this> */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(SupplierDebitNote::class, 'supplier_debit_note_id');
    }

    /** @return BelongsTo<InventoryReturnLine, $this> */
    public function inventoryReturnLine(): BelongsTo
    {
        return $this->belongsTo(InventoryReturnLine::class);
    }

    /** @return BelongsTo<BillLine, $this> */
    public function billLine(): BelongsTo
    {
        return $this->belongsTo(BillLine::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
