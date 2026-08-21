<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
final class WarehouseFactory extends Factory
{
    /** @var list<array{city: string, latitude: float, longitude: float}> */
    private const array Locations = [
        ['city' => 'Dubai', 'latitude' => 25.2048, 'longitude' => 55.2708],
        ['city' => 'Abu Dhabi', 'latitude' => 24.4539, 'longitude' => 54.3773],
        ['city' => 'Sharjah', 'latitude' => 25.3463, 'longitude' => 55.4209],
        ['city' => 'Ajman', 'latitude' => 25.4052, 'longitude' => 55.5136],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $location = self::Locations[fake()->numberBetween(0, count(self::Locations) - 1)];

        return [
            'name' => $location['city'].' Warehouse',
            'code' => mb_strtoupper(fake()->unique()->bothify('WH-###')),
            'address' => $location['city'].', United Arab Emirates',
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
