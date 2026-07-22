<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarehouseLocation>
 */
final class WarehouseLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'name' => 'Bin '.fake()->unique()->numerify('##'),
            'code' => mb_strtoupper(fake()->unique()->bothify('LOC-###')),
            'is_active' => true,
        ];
    }
}
