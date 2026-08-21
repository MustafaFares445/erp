<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalaryCalculationStatus;
use App\Services\Employees\SalaryCalculationService;
use Database\Factories\EmployeeSalaryCalculationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `payable_base`, `performance_percent`, `bonus_amount`, and `final_salary`
 * are deliberately absent from {@see self} fillable list — they are written
 * exclusively by {@see SalaryCalculationService} via
 * direct property assignment, never through mass assignment from a form
 * (data-model.md §12).
 */
#[Fillable(['sales_plan_id', 'employee_id', 'status', 'confirmed_by', 'confirmed_at', 'superseded_by_id', 'superseded_at'])]
final class EmployeeSalaryCalculation extends Model
{
    /** @use HasFactory<EmployeeSalaryCalculationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
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
        ];
    }

    /**
     * @return BelongsTo<SalesPlan, $this>
     */
    public function salesPlan(): BelongsTo
    {
        return $this->belongsTo(SalesPlan::class);
    }

    /**
     * @return BelongsTo<EmployeeProfile, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<EmployeeSalaryCalculation, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalaryCalculation::class, 'superseded_by_id');
    }

    /**
     * Every bonus suggestion for the same plan and employee this
     * calculation's `bonus_amount` was summed from — not a direct foreign
     * key, but the same `sales_plan_id` value on both sides.
     *
     * @return HasMany<BonusSuggestion, $this>
     */
    public function bonusSuggestions(): HasMany
    {
        return $this->hasMany(BonusSuggestion::class, 'sales_plan_id', 'sales_plan_id');
    }
}
