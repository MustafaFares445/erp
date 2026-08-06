<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesPlanStatus;
use App\Models\Concerns\TracksBlameable;
use Database\Factories\SalesPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'employee_id',
    'name',
    'month',
    'active_month',
    'task_weight',
    'visit_weight',
    'schedule_weight',
    'work_time_weight',
    'required_visit_minutes',
    'status',
])]
final class SalesPlan extends Model
{
    /** @use HasFactory<SalesPlanFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'month' => 'date',
            'active_month' => 'date',
            'task_weight' => 'decimal:2',
            'visit_weight' => 'decimal:2',
            'schedule_weight' => 'decimal:2',
            'work_time_weight' => 'decimal:2',
            'status' => SalesPlanStatus::class,
        ];
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * @return HasMany<PlanTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(PlanTask::class);
    }

    public function requiredVisitMinutes(): int
    {
        if ($this->required_visit_minutes !== null) {
            return $this->required_visit_minutes;
        }

        $default = config('employees.default_required_visit_minutes');

        return is_numeric($default) ? (int) $default : 30;
    }
}
