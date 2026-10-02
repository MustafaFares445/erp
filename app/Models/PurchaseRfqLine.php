<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchase_rfq_id', 'product_variant_id', 'unit_id', 'quantity', 'notes'])]
final class PurchaseRfqLine extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['quantity' => 'decimal:6'];
    }

    /** @return BelongsTo<PurchaseRfq, $this> */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id');
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

    /** @return HasMany<PurchaseRfqResponseLine, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(PurchaseRfqResponseLine::class);
    }
}
