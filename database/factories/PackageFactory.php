<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageType;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
final class PackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Package '.fake()->unique()->bothify('??##'),
            'package_type_id' => PackageType::factory(),
            'warehouse_id' => Warehouse::factory(),
            'is_active' => true,
        ];
    }
}
