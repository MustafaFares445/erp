<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlaPolicy>
 */
final class SlaPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'priority' => fake()->randomElement(TicketPriority::cases()),
            'response_target_minutes' => fake()->numberBetween(60, 1440),
            'resolution_target_minutes' => fake()->numberBetween(240, 4320),
        ];
    }
}
