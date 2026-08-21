<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalaryCalculationStatus;
use App\Models\EmployeeProfile;
use App\Models\EmployeeSalaryCalculation;
use App\Models\SalesPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeSalaryCalculation>
 */
final class EmployeeSalaryCalculationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sales_plan_id' => SalesPlan::factory(),
            'employee_id' => EmployeeProfile::factory(),
            'payable_base' => 5000.00,
            'performance_percent' => 80.00,
            'bonus_amount' => 0.00,
            'final_salary' => 4000.00,
            'status' => SalaryCalculationStatus::PendingConfirmation,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'superseded_by_id' => null,
            'superseded_at' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SalaryCalculationStatus::Confirmed,
            'confirmed_by' => User::factory()->admin(),
            'confirmed_at' => now(),
        ]);
    }

    public function superseded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SalaryCalculationStatus::Superseded,
            'confirmed_by' => User::factory()->admin(),
            'confirmed_at' => now()->subDay(),
            'superseded_by_id' => EmployeeSalaryCalculation::factory(),
            'superseded_at' => now(),
        ]);
    }
}
