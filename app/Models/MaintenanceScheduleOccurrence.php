<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OccurrenceStatus;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use Database\Factories\MaintenanceScheduleOccurrenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One due date on a {@see MaintenanceSchedule} (WP-3.6, GAP-MW-08, MT-07) —
 * the design decision that makes "was the one in March done" answerable: a
 * missed preventive service is a row here, never an absence. Entirely
 * service-owned (no fillable attributes) — mutated only by
 * {@see MaintenanceScheduleService} and
 * {@see MaintenanceScheduleGenerator}.
 */
final class MaintenanceScheduleOccurrence extends Model
{
    /** @use HasFactory<MaintenanceScheduleOccurrenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'due_on' => 'date',
            'status' => OccurrenceStatus::class,
            'raised_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MaintenanceSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'maintenance_schedule_id');
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }
}
