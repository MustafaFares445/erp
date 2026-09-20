<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerQuotationRequestLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_quotation_request_id', 'product_variant_id', 'requested_quantity',
    'requested_unit_id', 'customer_note', 'sort_order',
])]
final class CustomerQuotationRequestLine extends Model
{
    /** @use HasFactory<CustomerQuotationRequestLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<CustomerQuotationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(CustomerQuotationRequest::class, 'customer_quotation_request_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function requestedUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'requested_unit_id');
    }
}
