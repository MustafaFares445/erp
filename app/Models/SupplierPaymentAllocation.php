<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplierPaymentAllocationFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_payment_id', 'bill_id', 'amount'])]
final class SupplierPaymentAllocation extends Model
{
    /** @use HasFactory<SupplierPaymentAllocationFactory> */
    use HasFactory;

    #[\Override]
    protected static function booted(): void
    {
        self::deleting(function (): void {
            throw new DomainException('Supplier payment allocations are append-only evidence.');
        });
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    /** @return BelongsTo<Bill, $this> */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
