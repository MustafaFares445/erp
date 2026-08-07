<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BonusSuggestionStatus;
use App\Models\BonusSuggestion;
use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BonusSuggestion>
 */
final class BonusSuggestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => EmployeeProfile::factory(),
            'sales_plan_id' => SalesPlan::factory(),
            'sales_opportunity_draft_id' => null,
            'amount' => fake()->randomFloat(2, 50, 500),
            'reason' => fake()->sentence(8),
            'status' => BonusSuggestionStatus::Pending,
            'approved_by' => null,
            'approved_at' => null,
            'decision_notes' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BonusSuggestionStatus::Approved,
            'approved_by' => User::factory()->admin(),
            'approved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BonusSuggestionStatus::Rejected,
            'approved_by' => User::factory()->admin(),
            'approved_at' => now(),
        ]);
    }
}
