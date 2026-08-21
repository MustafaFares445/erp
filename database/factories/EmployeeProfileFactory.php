<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalaryCalculationMode;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
final class EmployeeProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->employee(),
            'employee_code' => fake()->unique()->bothify('EMP-####'),
            'job_title' => fake()->jobTitle(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'is_active' => true,
            'use_base_salary' => true,
            'base_salary' => fake()->randomFloat(2, 3000, 15000),
            'commission_target_amount' => null,
            'salary_calculation_mode' => SalaryCalculationMode::BasePlusPerformance,
        ];
    }

    public function baseSalary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'use_base_salary' => true,
            'base_salary' => fake()->randomFloat(2, 3000, 15000),
            'commission_target_amount' => null,
            'salary_calculation_mode' => SalaryCalculationMode::BasePlusPerformance,
        ]);
    }

    public function performanceOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'use_base_salary' => false,
            'base_salary' => null,
            'commission_target_amount' => fake()->randomFloat(2, 2000, 10000),
            'salary_calculation_mode' => SalaryCalculationMode::PerformanceOnly,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deleted_at' => now(),
        ]);
    }
}
