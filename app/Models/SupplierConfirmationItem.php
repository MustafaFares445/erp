<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierConfirmationStatus;
use Database\Factories\SupplierConfirmationItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_variant_id',
    'purchase_order_line_id',
    'requested_quantity',
    'requested_base_quantity',
    'confirmed_base_quantity',
    'backordered_base_quantity',
    'notes',
])]
final class SupplierConfirmationItem extends Model
{
    /** @use HasFactory<SupplierConfirmationItemFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:3',
            'requested_base_quantity' => 'decimal:6',
            'confirmed_base_quantity' => 'decimal:6',
            'backordered_base_quantity' => 'decimal:6',
            'confirmation_status' => SupplierConfirmationStatus::class,
            'promised_at' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SupplierConfirmation, $this> */
    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(SupplierConfirmation::class, 'supplier_confirmation_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isAnswered(): bool
    {
        return $this->confirmation_status->isAnswered();
    }
}
