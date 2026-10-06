<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesPlanStatus;
use App\Models\Concerns\TracksBlameable;
use Carbon\Carbon;
use Database\Factories\SalesPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'opportunity_weight',
    'required_visit_minutes',
    'status',
    'published_at',
    'published_by',
])]
final class SalesPlan extends Model
{
    /** @use HasFactory<SalesPlanFactory> */
    use HasFactory;

    use SoftDeletes;
    use TracksBlameable;

    /** @return array<string, string> */
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
            'opportunity_weight' => 'decimal:2',
            'published_at' => 'datetime',
            'status' => SalesPlanStatus::class,
        ];
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return HasMany<PlanTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(PlanTask::class);
    }

    /** @return HasMany<EmployeePerformanceScore, $this> */
    public function performanceScores(): HasMany
    {
        return $this->hasMany(EmployeePerformanceScore::class);
    }

    /** @return HasOne<EmployeePerformanceScore, $this> */
    public function performanceScore(): HasOne
    {
        return $this->hasOne(EmployeePerformanceScore::class)->latestOfMany('calculated_at');
    }

    /**
     * @return array{total_score: float, task_score: float, visit_score: float, schedule_score: float, work_time_score: float, opportunity_score: float, calculated_at: Carbon}|null
     */
    public function performanceSummary(): ?array
    {
        $score = $this->performanceScore;

        if (! $score instanceof EmployeePerformanceScore) {
            return null;
        }

        return [
            'total_score' => (float) $score->total_score,
            'task_score' => (float) $score->task_score,
            'visit_score' => (float) $score->visit_score,
            'schedule_score' => (float) $score->schedule_score,
            'work_time_score' => (float) $score->work_time_score,
            'opportunity_score' => (float) $score->opportunity_score,
            'calculated_at' => $score->calculated_at,
        ];
    }

    /** @return HasManyThrough<CustomerVisit, PlanTask, $this> */
    public function visits(): HasManyThrough
    {
        return $this->hasManyThrough(
            CustomerVisit::class,
            PlanTask::class,
            'sales_plan_id',
            'plan_task_id',
        );
    }

    /** @return HasMany<EmployeeSalaryCalculation, $this> */
    public function salaryCalculations(): HasMany
    {
        return $this->hasMany(EmployeeSalaryCalculation::class);
    }

    /** @return HasMany<BonusSuggestion, $this> */
    public function bonusSuggestions(): HasMany
    {
        return $this->hasMany(BonusSuggestion::class);
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
