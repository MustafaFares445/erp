<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TracksBlameable;
use App\Observers\CustomerProfileObserver;
use Database\Factories\CustomerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'user_id', 'customer_code', 'company_name', 'email', 'phone', 'address', 'country', 'city', 'latitude', 'longitude',
    'accountant_name', 'accountant_phone', 'accountant_email', 'contact_is_self', 'contact_name', 'contact_phone', 'contact_email', 'is_active',
])]
#[ObservedBy(CustomerProfileObserver::class)]
final class CustomerProfile extends Model implements HasMedia
{
    /** @use HasFactory<CustomerProfileFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'contact_is_self' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('license')->useDisk('local')->singleFile();
        $this->addMediaCollection('tax_certificate')->useDisk('local')->singleFile();
        $this->addMediaCollection('passport')->useDisk('local')->singleFile();
        $this->addMediaCollection('personal_identity')->useDisk('local')->singleFile();
        $this->addMediaCollection('accommodation')->useDisk('local')->singleFile();
    }
}
