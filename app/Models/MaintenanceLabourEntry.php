<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MaintenanceLabourEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Labour time spent on a {@see MaintenanceRecord} job, at a rate, so it can be
 * costed (WP-2.9, GAP-MW-09) and billed as a service line (GAP-MW-10). Never
 * mutated after creation — a correction is a new entry, not an edit, so the
 * cost history a job was billed from can never move under it.
 */
#[Fillable([
    'maintenance_record_id',
    'service_record_id',
    'employee_id',
    'performed_on',
    'minutes',
    'hourly_rate_minor',
    'total_cost_minor',
    'notes',
    'created_by',
])]
final class MaintenanceLabourEntry extends Model
{
    /** @use HasFactory<MaintenanceLabourEntryFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'performed_on' => 'date',
        ];
    }

    /** @return BelongsTo<MaintenanceRecord, $this> */
    public function maintenanceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class);
    }

    /** @return BelongsTo<MaintenanceTask, $this> */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTask::class, 'service_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
