<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmployeePerformanceScore;
use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeePerformanceScore>
 */
final class EmployeePerformanceScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_plan_id' => SalesPlan::factory(),
            'employee_id' => EmployeeProfile::factory(),
            'task_score' => 40.00,
            'visit_score' => 30.00,
            'schedule_score' => 20.00,
            'work_time_score' => 10.00,
            'total_score' => 100.00,
            'task_completion_percent' => 100.00,
            'calculation_breakdown' => [
                'task_completion' => ['numerator' => 5, 'denominator' => 5, 'ratio' => 1.0, 'weight' => 40.0, 'contribution' => 40.0],
                'visit_completion' => ['numerator' => 5, 'denominator' => 5, 'ratio' => 1.0, 'weight' => 30.0, 'contribution' => 30.0],
                'schedule_adherence' => ['numerator' => 5, 'denominator' => 5, 'ratio' => 1.0, 'weight' => 20.0, 'contribution' => 20.0],
                'work_time_adherence' => ['numerator' => 5, 'denominator' => 5, 'ratio' => 1.0, 'weight' => 10.0, 'contribution' => 10.0],
            ],
            'calculated_at' => now(),
        ];
    }

    public function zeroDenominator(): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_score' => 0.00,
            'visit_score' => 0.00,
            'schedule_score' => 0.00,
            'work_time_score' => 0.00,
            'total_score' => 0.00,
            'task_completion_percent' => 0.00,
            'calculation_breakdown' => [
                'task_completion' => ['numerator' => 0, 'denominator' => 0, 'ratio' => 0.0, 'weight' => 40.0, 'contribution' => 0.0],
                'visit_completion' => ['numerator' => 0, 'denominator' => 0, 'ratio' => 0.0, 'weight' => 30.0, 'contribution' => 0.0],
                'schedule_adherence' => ['numerator' => 0, 'denominator' => 0, 'ratio' => 0.0, 'weight' => 20.0, 'contribution' => 0.0],
                'work_time_adherence' => ['numerator' => 0, 'denominator' => 0, 'ratio' => 0.0, 'weight' => 10.0, 'contribution' => 0.0],
            ],
        ]);
    }
}
