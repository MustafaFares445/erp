<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['purchase_agreement_id', 'product_variant_id', 'unit_id', 'unit_price', 'minimum_order_quantity', 'lead_time_days'])]
final class PurchaseAgreementLine extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'minimum_order_quantity' => 'decimal:6',
            'lead_time_days' => 'integer',
        ];
    }

    /** @return BelongsTo<PurchaseAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(PurchaseAgreement::class, 'purchase_agreement_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
