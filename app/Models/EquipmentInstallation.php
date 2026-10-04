<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\EquipmentInstallationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Installation + commissioning + customer acceptance of one delivered
 * serialized unit, owned by an Installation-kind {@see MaintenanceRecord}.
 * Support records the service facts only; custody stays with Inventory.
 *
 * @property int $maintenance_record_id
 * @property int $serialized_inventory_unit_id
 */
#[Fillable([
    'maintenance_record_id',
    'serialized_inventory_unit_id',
    'shipment_id',
    'installed_by_employee_id',
    'installed_at',
    'commissioning_status',
    'commissioned_at',
    'commissioned_by_employee_id',
    'commissioning_failure_reason',
    'customer_acceptance_status',
    'customer_signatory_name',
    'customer_accepted_at',
    'customer_rejection_reason',
    'customer_rejected_at',
    'installation_location',
    'notes',
])]
final class EquipmentInstallation extends Model implements HasMedia
{
    /** @use HasFactory<EquipmentInstallationFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use TracksBlameable;

    public const string MEDIA_PHOTOS = 'installation-photos';

    public const string MEDIA_COMMISSIONING = 'commissioning-documents';

    public const string MEDIA_ACCEPTANCE = 'customer-acceptance';

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'commissioning_status' => CommissioningStatus::class,
            'commissioned_at' => 'datetime',
            'customer_acceptance_status' => CustomerAcceptanceStatus::class,
            'customer_accepted_at' => 'datetime',
            'customer_rejected_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_PHOTOS)->useDisk('local');
        $this->addMediaCollection(self::MEDIA_COMMISSIONING)->useDisk('local');
        $this->addMediaCollection(self::MEDIA_ACCEPTANCE)->useDisk('local');
    }

    public function isInstalled(): bool
    {
        return $this->installed_at !== null;
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

    /** @return BelongsTo<Shipment, $this> */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function installedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'installed_by_employee_id');
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function commissionedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'commissioned_by_employee_id');
    }

    /** @return HasMany<EquipmentInstallationCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(EquipmentInstallationCheck::class)->orderBy('sort_order')->orderBy('id');
    }
}
