<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceRecordService;
use Database\Factories\MaintenanceRecordFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business name "Maintenance Request" (data-model.md §6). Raised from a
 * ticket (`ticket_id` set, FR-060) or standalone (`ticket_id` null, FR-061).
 */
#[Fillable([
    'customer_id',
    'ticket_id',
    'product_variant_id',
    'serial_number',
    'serialized_inventory_unit_id',
    'is_equipment_unlinked',
    'warranty_status',
    'warranty_expiry_date',
    'description',
    'status',
    'billing_type',
    'quotation_id',
    'invoice_id',
    'billed_at',
])]
final class MaintenanceRecord extends Model
{
    /** @use HasFactory<MaintenanceRecordFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    protected static function booted(): void
    {
        self::saving(function (self $record): void {
            if ($record->warranty_status === WarrantyStatus::Covered && $record->warranty_expiry_date === null) {
                throw new DomainException('A warranty expiry date is required when warranty is covered.');
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'warranty_status' => WarrantyStatus::class,
            'warranty_expiry_date' => 'date',
            'is_equipment_unlinked' => 'boolean',
            'status' => MaintenanceStatus::class,
            'billing_type' => MaintenanceBillingType::class,
            'billed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /** @return HasOne<MaintenanceScheduleOccurrence, $this> */
    public function scheduleOccurrence(): HasOne
    {
        return $this->hasOne(MaintenanceScheduleOccurrence::class, 'maintenance_record_id');
    }

    /** @return HasMany<MaintenanceTask, $this> */
    public function serviceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    /** @return HasMany<MaintenanceLabourEntry, $this> */
    public function labourEntries(): HasMany
    {
        return $this->hasMany(MaintenanceLabourEntry::class);
    }

    /** @return HasMany<MaintenanceThirdPartyCost, $this> */
    public function thirdPartyCosts(): HasMany
    {
        return $this->hasMany(MaintenanceThirdPartyCost::class);
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
