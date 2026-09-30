<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'code', 'email', 'phone', 'address', 'logo_path', 'is_active', 'requires_confirmation'])]
final class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_confirmation' => 'boolean',
        ];
    }

    /** @return HasMany<SupplierProductReference, $this> */
    public function productReferences(): HasMany
    {
        return $this->hasMany(SupplierProductReference::class);
    }

    /** @return HasMany<SupplierProductReference, $this> */
    public function activeProductReferencesPreview(): HasMany
    {
        return $this->hasMany(SupplierProductReference::class)
            ->where('is_active', true)
            ->latest('id')
            ->limit(10);
    }

    /** @return HasMany<SupplierProductSupport, $this> */
    public function productSupports(): HasMany
    {
        return $this->hasMany(SupplierProductSupport::class);
    }

    /** @return HasMany<SupplierProductSupport, $this> */
    public function activeProductSupportsPreview(): HasMany
    {
        return $this->hasMany(SupplierProductSupport::class)
            ->where('is_active', true)
            ->latest('id')
            ->limit(10);
    }

    /** @return HasMany<InventoryOperation, $this> */
    public function inventoryOperations(): HasMany
    {
        return $this->hasMany(InventoryOperation::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** @return HasMany<PurchaseOrder, $this> */
    public function recentPurchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)
            ->latest('ordered_at')
            ->limit(10);
    }

    /** @return HasMany<SupplierConfirmation, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(SupplierConfirmation::class);
    }

    /** @return HasMany<Bill, $this> */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'resolved_supplier_id');
    }

    /** @return HasMany<Bill, $this> */
    public function recentBills(): HasMany
    {
        return $this->hasMany(Bill::class, 'resolved_supplier_id')
            ->latest('bill_date')
            ->limit(10);
    }

    /** @return HasMany<SupplierPayment, $this> */
    public function supplierPayments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
