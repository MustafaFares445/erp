<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplierProductSupportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['supplier_id', 'product_id', 'product_variant_id', 'is_active'])]
final class SupplierProductSupport extends Model
{
    /** @use HasFactory<SupplierProductSupportFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    #[\Override]
    protected static function booted(): void
    {
        self::saving(function (self $support): void {
            if (($support->product_id === null) === ($support->product_variant_id === null)) {
                throw new \LogicException('A supplier support must target exactly one product or product variant.');
            }
        });
    }
}
