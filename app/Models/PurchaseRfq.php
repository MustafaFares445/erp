<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PurchaseRfqStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['currency_code', 'needed_by', 'closes_at', 'notes'])]
final class PurchaseRfq extends Model
{
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => PurchaseRfqStatus::class,
            'needed_by' => 'date',
            'sent_at' => 'datetime',
            'closes_at' => 'datetime',
            'awarded_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /** @return HasMany<PurchaseRfqLine, $this> */
    public function lines(): HasMany { return $this->hasMany(PurchaseRfqLine::class); }
    /** @return HasMany<PurchaseRfqSupplier, $this> */
    public function suppliers(): HasMany { return $this->hasMany(PurchaseRfqSupplier::class); }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }

    /** @return BelongsTo<Supplier, $this> */
    public function awardedSupplier(): BelongsTo { return $this->belongsTo(Supplier::class, 'awarded_supplier_id'); }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function awardedPurchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'awarded_purchase_order_id'); }
}
