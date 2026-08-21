<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\WarrantyStatus;
use App\Models\Concerns\TracksBlameable;
use App\Services\Support\MaintenanceRecordService;
use Database\Factories\MaintenanceRecordFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business name "Maintenance Request" (data-model.md §6). Raised from a
 * ticket (`ticket_id` set, FR-060) or standalone (`ticket_id` null,
 * FR-061).
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
])]
final class MaintenanceRecord extends Model
{
    /** @use HasFactory<MaintenanceRecordFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * Defense-in-depth guard for FR-064, matching {@see EmployeeProfile}'s
     * `saving` pattern — {@see MaintenanceRecordService}
     * already validates this, but a direct model write (tinker, a future
     * API, a job) must not be able to bypass it.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::saving(function (self $record): void {
            if ($record->warranty_status === WarrantyStatus::Covered && $record->warranty_expiry_date === null) {
                throw new DomainException('A warranty expiry date is required when warranty is covered.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'warranty_status' => WarrantyStatus::class,
            'warranty_expiry_date' => 'date',
            'is_equipment_unlinked' => 'boolean',
            'status' => MaintenanceStatus::class,
        ];
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * The ticket this request was raised from — null when standalone
     * (FR-061).
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * The matched equipment unit (FR-062) — permanent once linked
     * (FR-068); null when the `serial_number` matched no known unit.
     *
     * @return BelongsTo<SerializedInventoryUnit, $this>
     */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /**
     * "Service Records" planned under this request (FR-070). Never
     * movable between parents (FR-071).
     *
     * @return HasMany<MaintenanceTask, $this>
     */
    public function serviceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }
}
