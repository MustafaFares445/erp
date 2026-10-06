<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalaryCalculationStatus;
use Database\Factories\EmployeeSalaryCalculationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'sales_plan_id',
    'employee_id',
    'performance_score_id',
    'status',
    'confirmed_by',
    'confirmed_at',
    'superseded_by_id',
    'superseded_at',
    'use_base_salary_snapshot',
    'base_salary_snapshot',
    'salary_calculation_mode_snapshot',
    'calculation_explanation',
])]
final class EmployeeSalaryCalculation extends Model
{
    /** @use HasFactory<EmployeeSalaryCalculationFactory> */
    use HasFactory;

    /** @return array<string, string> */
    #[\Override]
    public function casts(): array
    {
        return [
            'payable_base' => 'decimal:2',
            'performance_percent' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'final_salary' => 'decimal:2',
            'status' => SalaryCalculationStatus::class,
            'confirmed_at' => 'datetime',
            'superseded_at' => 'datetime',
            'use_base_salary_snapshot' => 'boolean',
            'base_salary_snapshot' => 'decimal:2',
            'calculation_explanation' => 'array',
        ];
    }

    /** @return BelongsTo<SalesPlan, $this> */
    public function salesPlan(): BelongsTo
    {
        return $this->belongsTo(SalesPlan::class);
    }

    /** @return BelongsTo<EmployeeProfile, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /** @return BelongsTo<EmployeePerformanceScore, $this> */
    public function performanceScore(): BelongsTo
    {
        return $this->belongsTo(EmployeePerformanceScore::class, 'performance_score_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<EmployeeSalaryCalculation, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryCalculation::class, 'superseded_by_id');
    }

    /** @return HasMany<BonusSuggestion, $this> */
    public function bonusSuggestions(): HasMany
    {
        return $this->hasMany(BonusSuggestion::class, 'sales_plan_id', 'sales_plan_id');
    }
}
