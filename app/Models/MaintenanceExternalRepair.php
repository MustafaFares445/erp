<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExternalRepairStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\MaintenanceExternalRepairFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A supplier / manufacturer repair (RMA) of one serialized unit, owned by a
 * maintenance request. Support records the service facts; Inventory moves the
 * unit into and out of supplier custody.
 *
 * @property int $id
 * @property int $maintenance_record_id
 * @property int $serialized_inventory_unit_id
 * @property int $supplier_id
 */
#[Fillable([
    'maintenance_record_id',
    'serialized_inventory_unit_id',
    'supplier_id',
    'rma_number',
    'supplier_reference',
    'status',
    'requested_at',
    'approved_at',
    'shipped_at',
    'supplier_received_at',
    'completed_at',
    'returned_at',
    'outbound_reference',
    'inbound_reference',
    'reason',
    'cancellation_reason',
    'supplier_diagnosis',
    'supplier_resolution',
    'estimated_return_on',
    'actual_return_on',
    'replacement_serialized_inventory_unit_id',
    'warranty_recovery_claim_id',
    'ship_inventory_movement_id',
    'return_inventory_movement_id',
])]
final class MaintenanceExternalRepair extends Model implements HasMedia
{
    /** @use HasFactory<MaintenanceExternalRepairFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use TracksBlameable;

    public const string MEDIA_RMA = 'rma-documents';

    public const string MEDIA_SUPPLIER_REPORTS = 'supplier-reports';

    public const string MEDIA_SHIPPING = 'shipping-documents';

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'status' => ExternalRepairStatus::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'shipped_at' => 'datetime',
            'supplier_received_at' => 'datetime',
            'completed_at' => 'datetime',
            'returned_at' => 'datetime',
            'estimated_return_on' => 'date',
            'actual_return_on' => 'date',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_RMA)->useDisk('local');
        $this->addMediaCollection(self::MEDIA_SUPPLIER_REPORTS)->useDisk('local');
        $this->addMediaCollection(self::MEDIA_SHIPPING)->useDisk('local');
    }

    public function isOverdue(): bool
    {
        return ! $this->status->isClosed()
            && $this->estimated_return_on !== null
            && $this->estimated_return_on->isPast();
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function replacementUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class, 'replacement_serialized_inventory_unit_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<WarrantyRecoveryClaim, $this> */
    public function warrantyRecoveryClaim(): BelongsTo
    {
        return $this->belongsTo(WarrantyRecoveryClaim::class);
    }
}
