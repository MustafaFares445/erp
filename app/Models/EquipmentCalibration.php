<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CalibrationResult;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\EquipmentCalibrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Calibration or validation of one serialized unit, owned by a
 * Calibration-kind {@see MaintenanceRecord}. A row exists from the moment the
 * calibration is started; `result` stays null until it is completed or failed.
 *
 * @property int $maintenance_record_id
 * @property int $serialized_inventory_unit_id
 */
#[Fillable([
    'maintenance_record_id',
    'serialized_inventory_unit_id',
    'performed_by_employee_id',
    'external_provider_id',
    'follow_up_maintenance_record_id',
    'started_at',
    'calibrated_at',
    'result',
    'failure_reason',
    'certificate_number',
    'certificate_expires_on',
    'certificate_issued_at',
    'next_calibration_due_on',
    'standard_reference',
    'instrument_reference',
    'notes',
])]
final class EquipmentCalibration extends Model implements HasMedia
{
    /** @use HasFactory<EquipmentCalibrationFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use TracksBlameable;

    public const string MEDIA_CERTIFICATES = 'calibration-certificates';

    public const string MEDIA_EVIDENCE = 'calibration-evidence';

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'calibrated_at' => 'datetime',
            'result' => CalibrationResult::class,
            'certificate_expires_on' => 'date',
            'certificate_issued_at' => 'datetime',
            'next_calibration_due_on' => 'date',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_CERTIFICATES)->useDisk('local');
        $this->addMediaCollection(self::MEDIA_EVIDENCE)->useDisk('local');
    }

    public function isFinished(): bool
    {
        return $this->result instanceof CalibrationResult;
    }

    public function hasCertificate(): bool
    {
        return $this->certificate_number !== null;
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function followUpMaintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class, 'follow_up_maintenance_record_id');
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'performed_by_employee_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function externalProvider(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'external_provider_id');
    }

    /** @return HasMany<EquipmentCalibrationMeasurement, $this> */
    public function measurements(): HasMany
    {
        return $this->hasMany(EquipmentCalibrationMeasurement::class)->orderBy('sort_order')->orderBy('id');
    }
}
