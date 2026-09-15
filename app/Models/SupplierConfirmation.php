<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupplierConfirmationStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\SupplierConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Append-only supplier evidence for one purchase order.
 *
 * @property int $id
 * @property int $supplier_id
 * @property int $purchase_order_id
 * @property SupplierConfirmationStatus $confirmation_status
 * @property Carbon|null $promised_at
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 * @property string|null $notes
 */
#[Fillable(['supplier_id', 'purchase_order_id', 'notes'])]
final class SupplierConfirmation extends Model
{
    /** @use HasFactory<SupplierConfirmationFactory> */
    use HasFactory;

    use TracksBlameable;

    protected $attributes = ['confirmation_status' => 'pending'];

    #[\Override]
    public function casts(): array
    {
        return [
            'confirmation_status' => SupplierConfirmationStatus::class,
            'promised_at' => 'date',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return HasMany<SupplierConfirmationItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierConfirmationItem::class);
    }

    public function isAnswered(): bool
    {
        return $this->confirmation_status->isAnswered();
    }
}
