<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerVisit;
use App\Models\VisitGpsLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitGpsLog>
 */
final class VisitGpsLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_visit_id' => CustomerVisit::factory(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'recorded_at' => now(),
        ];
    }
}
