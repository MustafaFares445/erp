<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use Database\Factories\MaintenanceScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A preventive-maintenance recurrence programme tied to one piece of
 * equipment (WP-3.6, GAP-MW-08, MT-07). `next_due_on`, `last_completed_on`,
 * and `is_active` are service-owned (mutated only by
 * {@see MaintenanceScheduleService} and
 * {@see MaintenanceScheduleGenerator}) and therefore
 * not fillable.
 */
#[Fillable([
    'schedule_number',
    'serialized_inventory_unit_id',
    'customer_id',
    'name',
    'interval_type',
    'interval_value',
    'lead_time_days',
    'first_due_on',
    'billing_type',
    'checklist',
])]
final class MaintenanceSchedule extends Model
{
    /** @use HasFactory<MaintenanceScheduleFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'interval_type' => MaintenanceIntervalType::class,
            'interval_value' => 'integer',
            'lead_time_days' => 'integer',
            'first_due_on' => 'date',
            'next_due_on' => 'date',
            'last_completed_on' => 'date',
            'is_active' => 'boolean',
            'billing_type' => MaintenanceBillingType::class,
            'checklist' => 'array',
        ];
    }

    /** @return BelongsTo<SerializedInventoryUnit, $this> */
    public function serializedInventoryUnit(): BelongsTo
    {
        return $this->belongsTo(SerializedInventoryUnit::class);
    }

    /** @return BelongsTo<CustomerProfile, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<MaintenanceScheduleOccurrence, $this> */
    public function occurrences(): HasMany
    {
        return $this->hasMany(MaintenanceScheduleOccurrence::class);
    }
}
