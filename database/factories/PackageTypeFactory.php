<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PackageType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageType>
 */
final class PackageTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'code' => mb_strtoupper(fake()->unique()->bothify('PKG-??##')),
            'is_active' => true,
        ];
    }
}
