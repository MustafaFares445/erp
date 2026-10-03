<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Enums\WriteOffStatus;
use App\Models\Concerns\HasCollaboration;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\TransitionsDocumentStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'invoice_number', 'customer_id', 'inventory_operation_id', 'order_id', 'maintenance_record_id', 'payment_term_id',
    'invoice_date', 'due_date', 'description', 'subtotal', 'tax_total', 'total_amount',
    'amount_paid', 'credited_amount', 'recognised_tax_amount', 'status', 'issued_at', 'sent_at',
])]
/**
 * @property int $id
 * @property string $invoice_number
 * @property int|null $customer_id
 * @property Carbon $invoice_date
 * @property Carbon|null $due_date
 * @property Carbon|null $issued_at
 * @property string $total_amount
 * @property string $amount_paid
 * @property string $credited_amount
 */
final class Invoice extends Model implements HasMedia
{
    use HasCollaboration;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;
    use TransitionsDocumentStatus;

    protected $attributes = [
        'status' => 'draft', 'subtotal' => 0, 'tax_total' => 0, 'total_amount' => 0,
        'amount_paid' => 0, 'credited_amount' => 0, 'recognised_tax_amount' => 0,
    ];

    /**
     * Issued or sent invoices — the states where `amount_paid`/`credited_amount`
     * are meaningful (a draft has neither payments nor a due date yet).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Sent->value]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIssuedThisMonth(Builder $query): Builder
    {
        return $query->whereNotNull('issued_at')
            ->whereBetween('issued_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    /**
     * Issued/sent with nothing paid or credited against it yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->active()
            ->where('amount_paid', 0)
            ->where('credited_amount', 0);
    }

    /**
     * Issued/sent with some but not full payment or credit applied.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePartiallyPaid(Builder $query): Builder
    {
        return $query->active()
            ->where('amount_paid', '>', 0)
            ->whereRaw('(amount_paid + credited_amount) < total_amount');
    }

    /**
     * Issued/sent, past due date, with outstanding balance remaining —
     * the same shape used by {@see self::isOverdue()}, expressed as a scope
     * for aggregate counting rather than per-record iteration.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today())
            ->whereRaw('(total_amount - amount_paid - credited_amount) > 0');
    }

    /**
     * Issued/sent with nothing left to collect — the "financially settled"
     * bucket surfaced as a List page tab.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->active()
            ->whereRaw('(total_amount - amount_paid - credited_amount) <= 0');
    }

    /**
     * Overdue, or with an unresolved automatic deposit-application failure —
     * the union of everything the List page's "Needs attention" tab surfaces.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->overdue()
            ->orWhereHas('depositApplicationIssues', fn (Builder $issues): Builder => $issues->whereNull('resolved_at')));
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return BelongsTo<InventoryOperation, $this>
     */
    #[\Deprecated(message: <<<'TXT'
    Single-delivery convenience reference, retained only so existing readers keep
                 working (WP-2.13, GAP-MW-13). Its unique index was dropped: a delivery is
                 invoiced at most once via {@see InvoiceDeliveryLink} instead, which is the only
                 control that covers consolidated and standalone invoices alike. This column is
                 still populated for a single-delivery invoice, but is null for a consolidated
                 one — read {@see self::deliveryLinks()} for the authoritative set of deliveries.
                 Slated for removal by WP-4.2 once no reader remains.
    TXT)]
    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    /**
     * Every delivery this invoice covers (WP-2.13, GAP-MW-13) — one row per delivery, whether the
     * invoice was raised from a single delivery, consolidated from several, or a standalone
     * invoice that was later attributed to one or more deliveries.
     *
     * @return HasMany<InvoiceDeliveryLink, $this>
     */
    public function deliveryLinks(): HasMany
    {
        return $this->hasMany(InvoiceDeliveryLink::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The service job this invoice bills (WP-2.9, GAP-MW-10) — null for every
     * other invoice.
     *
     * @return BelongsTo<MaintenanceRecord, $this>
     */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_confirmed_by');
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<InvoiceConfirmation, $this> */
    public function confirmations(): HasMany
    {
        return $this->hasMany(InvoiceConfirmation::class);
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /** @return HasMany<TaxRecognitionEntry, $this> */
    public function taxRecognitionEntries(): HasMany
    {
        return $this->hasMany(TaxRecognitionEntry::class);
    }

    /** @return HasMany<ReceivableWriteOff, $this> */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(ReceivableWriteOff::class);
    }

    /** @return HasMany<DepositApplicationIssue, $this> */
    public function depositApplicationIssues(): HasMany
    {
        return $this->hasMany(DepositApplicationIssue::class);
    }

    /** @return HasOne<ReceivableWriteOff, $this> */
    public function writeOff(): HasOne
    {
        return $this->hasOne(ReceivableWriteOff::class)
            ->where('status', WriteOffStatus::Approved->value)
            ->latestOfMany();
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2', 'total_amount' => 'decimal:2', 'amount_paid' => 'decimal:2',
            'credited_amount' => 'decimal:2', 'recognised_tax_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime', 'sent_at' => 'datetime',
            'received_confirmation_type' => InvoiceConfirmationType::class,
            'received_confirmed_at' => 'datetime',
        ];
    }

    public function writtenOffAmountMinor(): int
    {
        if ($this->relationLoaded('writeOffs')) {
            return $this->writeOffs
                ->filter(fn (ReceivableWriteOff $writeOff): bool => $writeOff->status === WriteOffStatus::Approved)
                ->sum(static fn (ReceivableWriteOff $writeOff): int => $writeOff->amount_minor);
        }

        return (int) $this->writeOffs()
            ->where('status', WriteOffStatus::Approved->value)
            ->sum('amount_minor');
    }

    public function receivableClaimMinor(): int
    {
        $totalMinor = JournalEntryLine::toMinorUnits($this->total_amount);
        $creditedMinor = JournalEntryLine::toMinorUnits($this->credited_amount);

        return max(0, $totalMinor - $creditedMinor);
    }

    public function amountPaidMinor(): int
    {
        return JournalEntryLine::toMinorUnits($this->amount_paid);
    }

    public function outstandingMinor(): int
    {
        return max(0, $this->receivableClaimMinor() - $this->amountPaidMinor() - $this->writtenOffAmountMinor());
    }

    public function outstandingAmount(): float
    {
        return $this->outstandingMinor() / 100;
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    public function isSent(): bool
    {
        return $this->status === InvoiceStatus::Sent;
    }

    public function isOverdue(?Carbon $asOf = null): bool
    {
        if (! $this->isIssued() || $this->outstandingAmount() <= 0.0 || ! $this->due_date instanceof Carbon) {
            return false;
        }

        $asOf ??= now();

        return $this->paymentTerm instanceof PaymentTerm
            ? $this->paymentTerm->isOverdueAt($this->due_date, $asOf)
            : $asOf->greaterThan($this->due_date);
    }

    /**
     * Deleting a draft returns every delivery it covered to the "uninvoiced" pool: the link rows
     * (unique on the delivery) and the deprecated single-delivery column both outlive a soft
     * delete, so without this the delivery would read as invoiced forever.
     */
    public function releaseDeliveryLinks(): void
    {
        $this->deliveryLinks()->delete();

        if ($this->inventory_operation_id !== null) {
            self::withTrashed()->whereKey($this->getKey())->toBase()->update(['inventory_operation_id' => null]);
            $this->setAttribute('inventory_operation_id', null);
            $this->syncOriginalAttribute('inventory_operation_id');
        }
    }

    /**
     * Runs the delete and its delivery-link release in one transaction so a failed delete can
     * never leave a draft that has already given up its deliveries.
     */
    #[\Override]
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('invoice-pdf')->useDisk('local');
    }

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $invoice): void {
            if ($invoice->getRawOriginal('issued_at') === null) {
                return;
            }

            if ($invoice->isDirty([
                'customer_id', 'inventory_operation_id', 'order_id', 'payment_term_id',
                'invoice_date', 'due_date', 'description', 'subtotal', 'tax_total', 'total_amount',
            ])) {
                throw new \DomainException('An issued invoice cannot be changed.');
            }
        });

        self::deleting(function (self $invoice): void {
            if ($invoice->isIssued()) {
                throw new \DomainException('An issued invoice cannot be deleted.');
            }

            $invoice->releaseDeliveryLinks();
        });
    }
}
