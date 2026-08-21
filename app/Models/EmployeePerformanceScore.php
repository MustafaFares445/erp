<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmployeePerformanceScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sales_plan_id',
    'employee_id',
    'task_score',
    'visit_score',
    'schedule_score',
    'work_time_score',
    'total_score',
    'task_completion_percent',
    'calculation_breakdown',
    'calculated_at',
])]
final class EmployeePerformanceScore extends Model
{
    /** @use HasFactory<EmployeePerformanceScoreFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function casts(): array
    {
        return [
            'task_score' => 'decimal:2',
            'visit_score' => 'decimal:2',
            'schedule_score' => 'decimal:2',
            'work_time_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'task_completion_percent' => 'decimal:2',
            'calculation_breakdown' => 'array',
            'calculated_at' => 'datetime',
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
}
