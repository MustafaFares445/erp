<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EmployeeReportType;
use App\Models\EmployeeReportExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeReportExport>
 */
final class EmployeeReportExportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => EmployeeReportType::PlanCompletion->value,
            'filters' => [],
            'file_path' => null,
            'status' => 'queued',
            'failure_reason' => null,
            'created_by' => User::factory()->admin(),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'file_path' => 'employee-reports/999.xlsx',
            'completed_at' => now(),
        ]);
    }
}
