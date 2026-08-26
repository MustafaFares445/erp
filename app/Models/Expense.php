<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use Database\Factories\ExpenseFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property string $expense_number
 * @property int|null $supplier_id
 * @property int|null $requested_by
 * @property int|null $payment_method_id
 * @property int|null $chart_account_id
 * @property Carbon $expense_date
 * @property Carbon|null $due_date
 * @property string $description
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total_amount
 * @property string $amount_paid
 * @property string|null $amount
 * @property string|null $tax_amount
 * @property string $status
 */
#[Fillable([
    'expense_number', 'supplier_id', 'requested_by', 'payment_method_id', 'chart_account_id',
    'expense_account_id', 'expense_date', 'due_date', 'merchant_name', 'description',
    'subtotal', 'tax_total', 'total_amount', 'amount_paid', 'amount', 'tax_amount', 'status', 'notes',
])]
final class Expense extends Model implements HasMedia
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    use InteractsWithMedia;
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
        self::creating(function (self $expense): void {
            if (blank($expense->getAttribute('expense_number'))) {
                $maxNumber = self::query()->lockForUpdate()->max('expense_number');
                $next = is_string($maxNumber) && preg_match('/(\d+)$/', $maxNumber, $matches) === 1
                    ? ((int) $matches[1]) + 1
                    : 1;
                $expense->setAttribute('expense_number', sprintf('EXP-%07d', $next));
            }
        });

        self::saving(function (self $expense): void {
            if ($expense->isDirty('amount') && ! $expense->isDirty('subtotal')) {
                $expense->setAttribute('subtotal', $expense->getAttribute('amount'));
            } elseif ($expense->isDirty('subtotal') && ! $expense->isDirty('amount')) {
                $expense->setAttribute('amount', $expense->getAttribute('subtotal'));
            }

            if ($expense->isDirty('tax_amount') && ! $expense->isDirty('tax_total')) {
                $expense->setAttribute('tax_total', $expense->getAttribute('tax_amount'));
            } elseif ($expense->isDirty('tax_total') && ! $expense->isDirty('tax_amount')) {
                $expense->setAttribute('tax_amount', $expense->getAttribute('tax_total'));
            }

            if (($expense->isDirty(['amount', 'tax_amount']) || $expense->isDirty(['subtotal', 'tax_total']))
                && ! $expense->isDirty('total_amount')) {
                $expense->setAttribute(
                    'total_amount',
                    number_format((float) $expense->subtotal + (float) $expense->tax_total, 2, '.', ''),
                );
            }

            if ($expense->isDirty('chart_account_id') && ! $expense->isDirty('expense_account_id')) {
                $expense->setAttribute('expense_account_id', $expense->getAttribute('chart_account_id'));
            } elseif ($expense->isDirty('expense_account_id') && ! $expense->isDirty('chart_account_id')) {
                $expense->setAttribute('chart_account_id', $expense->getAttribute('expense_account_id'));
            }

            if ($expense->exists && $expense->isFinanciallyImmutable()) {
                $protected = [
                    'supplier_id', 'requested_by', 'payment_method_id', 'chart_account_id',
                    'expense_account_id', 'expense_date', 'due_date', 'merchant_name', 'description',
                    'subtotal', 'tax_total', 'total_amount', 'amount', 'tax_amount',
                ];

                if ($expense->isDirty($protected)) {
                    throw new DomainException('An approved or paid expense cannot be changed.');
                }

                $originalRawStatus = $expense->getRawOriginal('status');
                $originalStatus = is_string($originalRawStatus) ? $originalRawStatus : '';
                if ($originalStatus === 'paid' && $expense->status !== 'paid') {
                    throw new DomainException('A paid expense cannot change status.');
                }

                if ($originalStatus === 'approved' && ! in_array($expense->status, ['approved', 'paid'], true)) {
                    throw new DomainException('An approved expense may only become paid.');
                }
            }
        });

        self::deleting(function (self $expense): void {
            if ($expense->isFinanciallyImmutable()) {
                throw new DomainException('An approved or paid expense cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class, 'expense_account_id');
    }

    /** @return BelongsTo<ChartAccount, $this> */
    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'requested_by');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
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
            'expense_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function outstandingAmount(): float
    {
        return max(0.0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    public function isFinanciallyImmutable(): bool
    {
        $status = $this->getRawOriginal('status');

        return is_string($status) && in_array($status, ['approved', 'paid'], true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('receipt')->useDisk('local')->singleFile();
    }
}
