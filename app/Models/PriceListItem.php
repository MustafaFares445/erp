<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'price_list_id',
    'product_id',
    'product_variant_id',
    'minimum_quantity',
    'price',
    'valid_from',
    'valid_to',
    'is_active',
])]
final class PriceListItem extends Model
{
    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $item): void {
            if ((float) $item->price < 0) {
                throw ValidationException::withMessages(['price' => 'Price must be zero or greater.']);
            }

            if ($item->minimum_quantity !== null && (float) $item->minimum_quantity <= 0) {
                throw ValidationException::withMessages([
                    'minimum_quantity' => 'Minimum quantity must be greater than zero.',
                ]);
            }

            if ($item->valid_from !== null && $item->valid_to !== null && $item->valid_from->isAfter($item->valid_to)) {
                throw ValidationException::withMessages([
                    'valid_to' => 'Valid to must be on or after valid from.',
                ]);
            }

            if ($item->product_variant_id !== null) {
                $matchesProduct = ProductVariant::query()
                    ->whereKey((int) $item->product_variant_id)
                    ->where('product_id', (int) $item->product_id)
                    ->exists();

                if (! $matchesProduct) {
                    throw ValidationException::withMessages([
                        'product_variant_id' => 'The selected variant must belong to the selected product.',
                    ]);
                }
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'minimum_quantity' => 'decimal:6',
            'price' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
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

    /** @param Builder<$this> $query
     * @return Builder<$this>
     */
    #[Scope]
    protected function current(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(static fn (Builder $dates): Builder => $dates
                ->whereNull('valid_from')
                ->orWhereDate('valid_from', '<=', today()))
            ->where(static fn (Builder $dates): Builder => $dates
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', today()));
    }
}
