<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ValidatesCurrencyCatalog;
use Database\Factories\SupplierProductReferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['supplier_id', 'product_variant_id', 'supplier_name', 'supplier_item_number', 'country_code', 'manufacturer', 'purchase_cost', 'currency_code', 'notes', 'availability_status', 'lead_time_days', 'minimum_order_quantity', 'is_preferred', 'is_active'])]
/**
 * @property int $id
 * @property int $supplier_id
 * @property int $product_variant_id
 * @property string $supplier_item_number
 */
final class SupplierProductReference extends Model
{
    /** @use HasFactory<SupplierProductReferenceFactory> */
    use HasFactory;

    use SoftDeletes;
    use ValidatesCurrencyCatalog;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $record): void {
            $record->validateActiveCurrency('currency_code');

            if ($record->isDirty('availability_status')) {
                $record->is_active = $record->availability_status === 'active';
            } elseif ($record->isDirty('is_active')) {
                $record->availability_status = $record->is_active ? 'active' : 'temporarily_unavailable';
            }
        });

        self::saved(static fn (self $record): mixed => self::syncSupport($record));
        self::deleted(static fn (self $record): mixed => self::syncSupport($record));
        self::restored(static fn (self $record): mixed => self::syncSupport($record));
    }

    #[\Override]
    public function casts(): array
    {
        return ['purchase_cost' => 'decimal:2', 'minimum_order_quantity' => 'decimal:3', 'lead_time_days' => 'integer', 'is_preferred' => 'boolean', 'is_active' => 'boolean'];
    }

    private static function syncSupport(self $record): SupplierProductSupport
    {
        $support = SupplierProductSupport::withTrashed()->firstOrNew([
            'supplier_id' => $record->supplier_id,
            'product_variant_id' => $record->product_variant_id,
        ]);

        $support->forceFill([
            'product_id' => null,
            'is_active' => $record->is_active && ! $record->trashed(),
            'deleted_at' => null,
        ])->save();

        return $support;
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * The single active reference for one supplier and variant, if any.
     *
     * A unique index guarantees there is at most one (V-14), so cost defaulting
     * and cost writeback both have an unambiguous target rather than having to
     * pick between rows.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActiveFor(Builder $query, int $supplierId, int $productVariantId): Builder
    {
        return $query->where('supplier_id', $supplierId)
            ->where('product_variant_id', $productVariantId)
            ->where('availability_status', 'active')
            ->where('is_active', true);
    }
}
