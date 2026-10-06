<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OperationType;
use App\Models\Concerns\Favoritable;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasFavorites;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\ValidatesCurrencyCatalog;
use App\Policies\SupplierPolicy;
use Closure;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'code', 'email', 'phone', 'address', 'default_currency_code', 'payment_term_id', 'default_lead_time_days', 'notes', 'logo_path', 'is_active', 'requires_confirmation'])]
final class Supplier extends Model implements Favoritable
{
    use HasCustomFields;

    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use HasFavorites;
    use SoftDeletes;
    use TracksBlameable;
    use ValidatesCurrencyCatalog;

    /** Attribute set by {@see self::referenceFlagRelations()}: the supplier has receipt operations. */
    public const string RECEIPT_OPERATIONS_EXISTS = 'receipt_operations_exists';

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $supplier): void {
            if ($supplier->default_currency_code !== null) {
                $supplier->validateActiveCurrency('default_currency_code');
            }
        });
    }

    #[\Override]
    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_confirmation' => 'boolean',
            'default_lead_time_days' => 'integer',
        ];
    }

    /**
     * The relations to hand to `withExists()` so a list preloads, in its one query, every
     * "is this supplier still referenced?" fact that {@see SupplierPolicy} needs
     * to decide deletion, instead of running those existence checks once per row.
     *
     * @return array<int|string, string|Closure(Builder<*>): mixed>
     */
    public static function referenceFlagRelations(): array
    {
        return [
            'productReferences',
            'productSupports',
            'purchaseOrders',
            'confirmations',
            'bills',
            'supplierPayments',
            'inventoryOperations as '.self::RECEIPT_OPERATIONS_EXISTS => static fn (Builder $operations): Builder => $operations
                ->where('operation_type', OperationType::Receipt),
        ];
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
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
