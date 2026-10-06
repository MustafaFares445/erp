<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerApprovalStatus;
use App\Enums\CustomerType;
use App\Enums\OperationStage;
use App\Enums\PaymentStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\UserType;
use App\Models\Concerns\Favoritable;
use App\Models\Concerns\HasCollaboration;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasFavorites;
use App\Models\Concerns\TracksBlameable;
use App\Models\Concerns\ValidatesCurrencyCatalog;
use App\Observers\CustomerProfileObserver;
use App\Services\Payments\CustomerDepositApplicationService;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'user_id', 'customer_code', 'company_name', 'customer_type', 'customer_group_id', 'default_currency_code', 'default_price_list_id',
    'default_payment_term_id', 'assigned_sales_employee_id', 'email', 'phone', 'address', 'billing_address', 'tax_registration_number',
    'country', 'city', 'latitude', 'longitude', 'accountant_name', 'accountant_phone', 'accountant_email', 'contact_is_self',
    'contact_name', 'contact_phone', 'contact_email', 'is_active', 'approval_status', 'reviewed_by', 'reviewed_at', 'review_note', 'allow_direct_orders',
])]
#[ObservedBy(CustomerProfileObserver::class)]
/**
 * @property int $id
 * @property string|null $company_name
 * @property string|null $customer_code
 */
final class CustomerProfile extends Model implements Favoritable, HasMedia
{
    use HasCollaboration;
    use HasCustomFields;

    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    use HasFavorites;
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;
    use ValidatesCurrencyCatalog;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(static function (self $customer): void {
            if ($customer->default_currency_code !== null) {
                $customer->validateActiveCurrency('default_currency_code');
            }

            if ($customer->default_price_list_id !== null) {
                $priceList = PriceList::query()->active()->find($customer->default_price_list_id);

                if (! $priceList instanceof PriceList) {
                    throw ValidationException::withMessages([
                        'default_price_list_id' => 'The default price list must be active.',
                    ]);
                }

                if ($customer->default_currency_code === null) {
                    $customer->default_currency_code = $priceList->currency_code;
                } elseif (mb_strtoupper((string) $customer->default_currency_code) !== mb_strtoupper((string) $priceList->currency_code)) {
                    throw ValidationException::withMessages([
                        'default_price_list_id' => 'The default price list currency must match the customer default currency.',
                    ]);
                }
            }

            if ($customer->customer_group_id !== null
                && ! CustomerGroup::query()->whereKey($customer->customer_group_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'customer_group_id' => 'The customer group must be active.',
                ]);
            }

            if ($customer->assigned_sales_employee_id !== null
                && ! User::query()->whereKey($customer->assigned_sales_employee_id)->where('user_type', UserType::Employee->value)->exists()) {
                throw ValidationException::withMessages([
                    'assigned_sales_employee_id' => 'The assigned sales employee must be an employee account.',
                ]);
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'contact_is_self' => 'boolean',
            'customer_type' => CustomerType::class,
            'is_active' => 'boolean',
            'approval_status' => CustomerApprovalStatus::class,
            'reviewed_at' => 'datetime',
            'allow_direct_orders' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function defaultPaymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'default_payment_term_id');
    }

    /** @return BelongsTo<PriceList, $this> */
    public function defaultPriceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'default_price_list_id');
    }

    /** @return BelongsTo<CustomerGroup, $this> */
    public function customerGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedSalesEmployee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_sales_employee_id');
    }

    /** @return BelongsToMany<PriceList, $this> */
    public function priceLists(): BelongsToMany
    {
        return $this->belongsToMany(PriceList::class, 'price_list_customer')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<CustomerDeliveryAddress, $this> */
    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(CustomerDeliveryAddress::class);
    }

    /** @return MorphMany<Interaction, $this> */
    public function interactions(): MorphMany
    {
        return $this->morphMany(Interaction::class, 'subject')->latest('occurred_at');
    }

    /**
     * The single most recent interaction, for cheap eager loading on a list
     * of customers (WP-3.1, GAP-UI-03) — Eloquent's "latest of many" keeps
     * this to one query regardless of row count, where loading `interactions()`
     * for every row would not.
     *
     * @return MorphOne<Interaction, $this>
     */
    public function latestInteraction(): MorphOne
    {
        return $this->morphOne(Interaction::class, 'subject')->latestOfMany('occurred_at');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'converted_customer_id');
    }

    /**
     * @return HasMany<SalesOpportunity, $this>
     *
     * @noinspection PhpEloquentFkMismatchInspection FK is explicit because
     * Eloquent's default ("customer_profile_id") does not match the actual
     * `customer_id` column (fixed alongside WP-3.1, GAP-UI-03 — this relation
     * had never been exercised, so the mismatch was silent).
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(SalesOpportunity::class, 'customer_id');
    }

    /**
     * Every remaining relation below reaches a table that already carries a
     * `customer_id` foreign key (WP-3.1, GAP-UI-03) — the data was always
     * reachable from the other side; what was missing was the ability to
     * reach it from here. The FK is explicit throughout because Eloquent's
     * default ("customer_profile_id") does not match the actual column.
     *
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'customer_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'customer_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'customer_id');
    }

    /** @return HasMany<CreditNote, $this> */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'customer_id');
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'customer_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'customer_id');
    }

    /** @return HasMany<CustomerVisit, $this> */
    public function visits(): HasMany
    {
        return $this->hasMany(CustomerVisit::class, 'customer_id');
    }

    /** @return HasMany<InventoryOperation, $this> */
    public function deliveriesAwaitingInvoice(): HasMany
    {
        return $this->hasMany(InventoryOperation::class, 'customer_id')
            ->where('stage', OperationStage::Done->value)
            ->whereDoesntHave('invoiceDeliveryLink');
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class, 'customer_id');
    }

    /** @return HasMany<ReceivableWriteOff, $this> */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(ReceivableWriteOff::class, 'customer_id');
    }

    /** @return HasMany<CustomerProfileChangeRequest, $this> */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(CustomerProfileChangeRequest::class, 'customer_id');
    }

    /** @return HasMany<CustomerQuotationRequest, $this> */
    public function quotationRequests(): HasMany
    {
        return $this->hasMany(CustomerQuotationRequest::class, 'customer_id');
    }

    /** @return HasMany<CustomerReturnRequest, $this> */
    public function returnRequests(): HasMany
    {
        return $this->hasMany(CustomerReturnRequest::class, 'customer_id');
    }

    /** @return HasMany<PaymentTransaction, $this> */
    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'customer_id');
    }

    /**
     * Serialized units currently in this customer's custody — their owned,
     * warranty-tracked equipment. `custody_reference_id` is a loose
     * discriminated reference (see {@see SerializedInventoryUnit}), not a
     * true polymorphic FK, so this is a plain {@see HasMany} narrowed by the
     * matching custody type/reference type rather than a `morphMany`.
     *
     * @return HasMany<SerializedInventoryUnit, $this>
     */
    public function ownedEquipment(): HasMany
    {
        return $this->hasMany(SerializedInventoryUnit::class, 'custody_reference_id')
            ->where('custody_type', SerializedCustodyType::Customer->value)
            ->where('custody_reference_type', 'customer');
    }

    /** @return HasMany<WarrantyEntitlement, $this> */
    public function warrantyEntitlements(): HasMany
    {
        return $this->hasMany(WarrantyEntitlement::class, 'customer_id')->latest('id');
    }

    /** @return HasMany<SupportEntitlement, $this> */
    public function supportEntitlements(): HasMany
    {
        return $this->hasMany(SupportEntitlement::class, 'customer_id')->latest('id');
    }

    /**
     * Total unallocated remainder across every Posted payment for this
     * customer — the same "Customer Deposit" balance
     * {@see CustomerDepositApplicationService}
     * draws down against an issued invoice's outstanding balance.
     */
    public function depositBalance(): float
    {
        return (float) $this->payments()
            ->where('status', PaymentStatus::Posted->value)
            ->get()
            ->sum(fn (Payment $payment): float => (float) $payment->amount - (float) $payment->allocations()->sum('amount'));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('license')->useDisk('local')->singleFile();
        $this->addMediaCollection('tax_certificate')->useDisk('local')->singleFile();
        $this->addMediaCollection('passport')->useDisk('local')->singleFile();
        $this->addMediaCollection('personal_identity')->useDisk('local')->singleFile();
        $this->addMediaCollection('accommodation')->useDisk('local')->singleFile();
    }
}
