<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BusinessConstraintKey;
use App\Models\ConstraintOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConstraintOverride>
 */
class ConstraintOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'constraint_key' => BusinessConstraintKey::MaxDiscountPercent,
            'subject_type' => null,
            'subject_id' => null,
            'attempted_value' => 40.0,
            'limit_value' => 25.0,
            'reason' => fake()->sentence(),
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ];
    }

    public function forValue(BusinessConstraintKey $key, float $attempted, float $limit): self
    {
        return $this->state(fn (): array => [
            'constraint_key' => $key,
            'attempted_value' => $attempted,
            'limit_value' => $limit,
        ]);
    }
}
