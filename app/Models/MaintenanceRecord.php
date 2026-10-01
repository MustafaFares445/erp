<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyFailureCategory;
use App\Enums\WarrantyStatus;
use App\Models\Concerns\TracksBlameable;
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
 *
 * @property int $customer_id
 * @property int|null $serialized_inventory_unit_id
 * @property int|null $quotation_id
 * @property int|null $invoice_id
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
    'diagnosis_summary',
    'root_cause',
    'failure_category',
    'diagnosed_at',
    'diagnosed_by',
    'coverage_decision',
    'coverage_source',
    'coverage_reason',
    'customer_coverage_explanation',
    'coverage_decided_at',
    'coverage_decided_by',
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
            'failure_category' => WarrantyFailureCategory::class,
            'diagnosed_at' => 'datetime',
            'coverage_decision' => WarrantyClaimDecision::class,
            'coverage_source' => WarrantyCoverageSource::class,
            'coverage_decided_at' => 'datetime',
            'is_equipment_unlinked' => 'boolean',
            'status' => MaintenanceStatus::class,
            'billing_type' => MaintenanceBillingType::class,
            'billed_at' => 'datetime',
        ];
    }

    /**
     * Whether the request has reached a terminal lifecycle state.
     */
    public function isFinalised(): bool
    {
        return in_array($this->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true);
    }

    /**
     * Whether a quotation, invoice or coverage settlement has already been
     * raised against the request. A not-yet-refreshed model whose billing type
     * was never loaded counts as unbilled, matching the column default.
     */
    public function hasBillingActivity(): bool
    {
        $billingType = $this->getAttribute('billing_type');

        return ($billingType instanceof MaintenanceBillingType && $billingType !== MaintenanceBillingType::Unbilled)
            || $this->quotation_id !== null
            || $this->invoice_id !== null;
    }

    /**
     * Whether repair work (starting a service record, consuming parts) may
     * proceed: never while the customer has yet to accept a chargeable
     * quotation, and never once the request is closed or cancelled.
     */
    public function allowsRepairWork(): bool
    {
        return $this->status !== MaintenanceStatus::AwaitingApproval && ! $this->isFinalised();
    }

    /**
     * Whether the request's details, warranty data, assessment and costs may
     * still change: it must be neither finalised nor commercially billed.
     */
    public function isLockedForChanges(): bool
    {
        if ($this->isFinalised()) {
            return true;
        }

        return $this->hasBillingActivity();
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

    /** @return HasMany<MaintenanceCoverageLine, $this> */
    public function coverageLines(): HasMany
    {
        return $this->hasMany(MaintenanceCoverageLine::class);
    }

    /** @return HasOne<WarrantyRecoveryClaim, $this> */
    public function warrantyRecoveryClaim(): HasOne
    {
        return $this->hasOne(WarrantyRecoveryClaim::class);
    }

    /** @return BelongsTo<User, $this> */
    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function coverageDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coverage_decided_by');
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
