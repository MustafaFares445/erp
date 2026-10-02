<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['purchase_rfq_id', 'supplier_id'])]
final class PurchaseRfqSupplier extends Model
{
    #[\Override]
    public function casts(): array
    {
        return ['sent_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    /** @return BelongsTo<PurchaseRfq, $this> */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<PurchaseRfqResponseLine, $this> */
    public function responseLines(): HasMany
    {
        return $this->hasMany(PurchaseRfqResponseLine::class);
    }
}
