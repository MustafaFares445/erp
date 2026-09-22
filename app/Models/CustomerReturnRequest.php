<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerReturnRequestStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Crm\CustomerReturnRequestService;
use App\Services\Inventory\InventoryReturnService;
use Database\Factories\CustomerReturnRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's request to return already-delivered goods. Never moves stock
 * or touches accounting on its own — only
 * {@see CustomerReturnRequestService::convertToInventoryReturn()}
 * hands an approved request to the existing, untouched
 * {@see InventoryReturnService}.
 */
#[Fillable([
    'request_number', 'customer_id', 'original_inventory_operation_id', 'reason', 'status',
    'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note', 'resulting_inventory_return_id', 'source_channel',
])]
final class CustomerReturnRequest extends Model
{
    /** @use HasFactory<CustomerReturnRequestFactory> */
    use HasFactory;

    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => CustomerReturnRequestStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function originalOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class, 'original_inventory_operation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<InventoryReturn, $this> */
    public function resultingInventoryReturn(): BelongsTo
    {
        return $this->belongsTo(InventoryReturn::class, 'resulting_inventory_return_id');
    }

    /** @return HasMany<CustomerReturnRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CustomerReturnRequestLine::class)->orderBy('sort_order');
    }

    public function isOpen(): bool
    {
        return ! $this->status->isTerminal();
    }
}
