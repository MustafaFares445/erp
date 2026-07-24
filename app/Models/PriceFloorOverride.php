<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PriceFloorOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_variant_id', 'customer_user_id', 'attempted_price', 'min_price', 'approved_by', 'approved_at', 'reason'])]
final class PriceFloorOverride extends Model
{
    /** @use HasFactory<PriceFloorOverrideFactory> */
    use HasFactory;

    #[\Override]
    public function casts(): array
    {
        return ['attempted_price' => 'decimal:2', 'min_price' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
