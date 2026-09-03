<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Domain\DuplicateSupplierReference;
use App\Exceptions\Domain\SupplierReferenceRequired;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\BillFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $bill_number
 * @property int $supplier_id
 * @property string $supplier_reference
 * @property int|null $purchase_order_id
 * @property int|null $payment_term_id
 * @property Carbon $bill_date
 * @property Carbon|null $due_date
 * @property string $description
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total_amount
 * @property string $amount_paid
 * @property string|null $grand_total
 * @property string|null $paid_amount
 * @property string $status
 */
#[Fillable([
    'bill_number', 'supplier_id', 'supplier_reference', 'purchase_order_id', 'payment_term_id',
    'expense_account_id', 'bill_date', 'due_date', 'description', 'subtotal', 'tax_total',
    'total_amount', 'amount_paid', 'grand_total', 'paid_amount', 'status', 'notes',
])]
final class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    protected $attributes = [
        'status' => 'draft',
        'subtotal' => 0,
        'tax_total' => 0,
        'total_amount' => 0,
        'amount_paid' => 0,
    ];

    #[\Override]
    protected static function booted(): void
    {
        self::creating(function (self $bill): void {
            if (blank($bill->getAttribute('bill_number'))) {
                $maxNumber = self::query()->lockForUpdate()->max('bill_number');
                $next = is_string($maxNumber) && preg_match('/(\d+)$/', $maxNumber, $matches) === 1
                    ? ((int) $matches[1]) + 1
                    : 1;
                $bill->setAttribute('bill_number', sprintf('BILL-%07d', $next));
            }
        });

        self::saving(function (self $bill): void {
            $value = $bill->supplier_reference;
            $reference = is_string($value) ? trim($value) : '';

            if ($reference === '') {
                throw SupplierReferenceRequired::make();
            }

            $bill->setAttribute('supplier_reference', $reference);

            $duplicate = self::withTrashed()
                ->where('supplier_id', $bill->supplier_id)
                ->where('supplier_reference', $reference)
                ->when($bill->exists, fn (Builder $query): Builder => $query->whereKeyNot($bill->getKey()))
                ->exists();

            if ($duplicate) {
                throw DuplicateSupplierReference::forReference($reference);
            }

            if ($bill->payment_term_id !== null) {
                $term = PaymentTerm::query()->find($bill->payment_term_id);
                if ($term instanceof PaymentTerm) {
                    $bill->setAttribute('due_date', Carbon::parse($bill->bill_date)->addDays($term->due_days)->toDateString());
                }
            }

            if ($bill->isDirty('grand_total') && ! $bill->isDirty('total_amount')) {
                $bill->setAttribute('total_amount', $bill->getAttribute('grand_total'));
            } elseif ($bill->isDirty('total_amount') && ! $bill->isDirty('grand_total')) {
                $bill->setAttribute('grand_total', $bill->getAttribute('total_amount'));
            }

            if ($bill->isDirty('paid_amount') && ! $bill->isDirty('amount_paid')) {
                $bill->setAttribute('amount_paid', $bill->getAttribute('paid_amount'));
            } elseif ($bill->isDirty('amount_paid') && ! $bill->isDirty('paid_amount')) {
                $bill->setAttribute('paid_amount', $bill->getAttribute('amount_paid'));
            }

            if ($bill->exists && $bill->isFinanciallyImmutable()) {
                $protected = [
                    'supplier_id', 'supplier_reference', 'purchase_order_id', 'payment_term_id',
                    'expense_account_id', 'bill_date', 'due_date', 'description', 'subtotal',
                    'tax_total', 'total_amount', 'grand_total',
                ];

                if ($bill->isDirty($protected)) {
                    throw new DomainException('An approved or paid bill cannot be changed.');
                }

                $originalRawStatus = $bill->getRawOriginal('status');
                $originalStatus = is_string($originalRawStatus) ? $originalRawStatus : '';
                $currentStatus = $bill->status;
                $allowed = match ($originalStatus) {
                    'approved' => ['approved', 'partially_paid', 'paid'],
                    'partially_paid' => ['partially_paid', 'paid'],
                    'paid' => ['paid'],
                    default => [$originalStatus],
                };

                if (! in_array($currentStatus, $allowed, true)) {
                    throw new DomainException('An approved or paid bill cannot move backwards in its lifecycle.');
                }
            }
        });

        self::deleting(function (self $bill): void {
            if ($bill->isFinanciallyImmutable()) {
                throw new DomainException('An approved or paid bill cannot be deleted.');
            }
        });
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

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'expense_account_id');
    }

    /** @return HasMany<BillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('sort_order');
    }

    /** @return HasMany<SupplierPaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return MorphOne<JournalEntry, $this> */
    public function sourceJournalEntry(): MorphOne
    {
        return $this->morphOne(JournalEntry::class, 'source');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'supplier_reference_backfilled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function outstandingAmount(): float
    {
        return max(0.0, (float) $this->grandTotal() - (float) $this->paidAmount());
    }

    public function grandTotal(): string
    {
        return $this->grand_total ?? $this->total_amount;
    }

    public function paidAmount(): string
    {
        return $this->paid_amount ?? $this->amount_paid;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinanciallyImmutable(): bool
    {
        $status = $this->getRawOriginal('status');

        return is_string($status) && in_array($status, ['approved', 'partially_paid', 'paid'], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['approved', 'partially_paid'], true);
    }
}
