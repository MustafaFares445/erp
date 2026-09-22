<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerReturnRequestLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_return_request_id', 'original_inventory_operation_line_id', 'requested_quantity', 'customer_note', 'sort_order',
])]
final class CustomerReturnRequestLine extends Model
{
    /** @use HasFactory<CustomerReturnRequestLineFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:6',
        ];
    }

    /** @return BelongsTo<CustomerReturnRequest, $this> */
    public function customerReturnRequest(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnRequest::class);
    }

    /** @return BelongsTo<InventoryOperationLine, $this> */
    public function originalOperationLine(): BelongsTo
    {
        return $this->belongsTo(InventoryOperationLine::class, 'original_inventory_operation_line_id');
    }
}
