<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseInboundStatus;
use App\Services\Purchasing\PurchaseInboundService;
use Database\Factories\PurchaseInboundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The warehouse-allocation aggregate for one accepted purchase order.
 *
 * Created and advanced only by {@see PurchaseInboundService}, never by a
 * form — `status`, `activated_at`, `allocation_confirmed_at`, and
 * `completed_at` are all absent from `#[Fillable]` for the same reason
 * {@see PurchaseOrder} keeps its own status columns service-owned.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property PurchaseInboundStatus $status
 * @property Carbon|null $activated_at
 * @property Carbon|null $allocation_confirmed_at
 * @property Carbon|null $completed_at
 * @property PurchaseOrder $purchaseOrder
 * @property Collection<int, PurchaseInboundLine> $lines
 */
#[Fillable([
    'purchase_order_id',
])]
final class PurchaseInbound extends Model
{
    /** @use HasFactory<PurchaseInboundFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'awaiting_allocation',
    ];

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => PurchaseInboundStatus::class,
            'activated_at' => 'datetime',
            'allocation_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<PurchaseInboundLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInboundLine::class);
    }
}
