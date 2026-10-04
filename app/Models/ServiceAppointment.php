<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceKind;
use App\Enums\ServiceAppointmentStatus;
use App\Models\Concerns\TracksBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[Fillable([
    'maintenance_task_id',
    'employee_id',
    'status',
    'scheduled_start_at',
    'scheduled_end_at',
    'estimated_duration_minutes',
    'address_snapshot',
    'latitude',
    'longitude',
    'dispatched_at',
    'en_route_at',
    'checked_in_at',
    'check_in_latitude',
    'check_in_longitude',
    'checked_out_at',
    'check_out_latitude',
    'check_out_longitude',
    'customer_signature_name',
    'notes',
])]
final class ServiceAppointment extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    public function casts(): array
    {
        return [
            'status' => ServiceAppointmentStatus::class,
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'estimated_duration_minutes' => 'integer',
            'address_snapshot' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'dispatched_at' => 'datetime',
            'en_route_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'check_in_latitude' => 'decimal:7',
            'check_in_longitude' => 'decimal:7',
            'checked_out_at' => 'datetime',
            'check_out_latitude' => 'decimal:7',
            'check_out_longitude' => 'decimal:7',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('appointment-evidence')->useDisk('local');
        $this->addMediaCollection('customer-signature')->useDisk('local')->singleFile();
    }

    /** @return BelongsTo<MaintenanceTask, $this> */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'maintenance_task_id');
    }

    /** The visit's purpose, derived from its maintenance work (no duplicate column). */
    public function purpose(): ?MaintenanceKind
    {
        return $this->serviceRecord?->maintenanceRecord?->maintenance_kind;
    }

    public function isInstallation(): bool
    {
        return $this->purpose() === MaintenanceKind::Installation;
    }

    public function isCalibration(): bool
    {
        return $this->purpose() === MaintenanceKind::Calibration;
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }
}
