<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\MaintenanceTaskFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business name "Service Record" (data-model.md §7). Belongs to exactly one
 * Maintenance Request for its whole lifetime — never movable between parents.
 */
#[Fillable([
    'maintenance_record_id',
    'employee_id',
    'title',
    'description',
    'due_at',
    'started_at',
    'completed_at',
    'work_performed',
    'completion_notes',
    'status',
])]
final class MaintenanceTask extends Model
{
    /** @use HasFactory<MaintenanceTaskFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    #[\Override]
    protected static function booted(): void
    {
        self::updating(function (self $task): void {
            if ($task->isDirty('maintenance_record_id')) {
                throw new DomainException('A service record cannot be moved between maintenance requests.');
            }
        });
    }

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'status' => MaintenanceStatus::class,
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return HasMany<ServiceRecordPart, $this> */
    public function parts(): HasMany
    {
        return $this->hasMany(ServiceRecordPart::class);
    }
}
