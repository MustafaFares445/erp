<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InvoiceLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_variant_id', 'order_line_id', 'description', 'quantity', 'unit_price',
    'tax_amount', 'line_total', 'sort_order',
])]
final class InvoiceLine extends Model
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<OrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function creditNoteLines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $line): void {
            if ($line->invoice()->whereNotNull('issued_at')->exists()) {
                throw new \DomainException('An issued invoice line is immutable.');
            }
        });

        self::deleting(function (self $line): void {
            if ($line->invoice()->whereNotNull('issued_at')->exists()) {
                throw new \DomainException('An issued invoice line cannot be deleted.');
            }
        });
    }
}
