<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Manufacturer>
 */
final class ManufacturerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'code' => mb_strtoupper(fake()->unique()->bothify('MFG-####')),
            'country_code' => fake()->countryCode(),
            'website' => fake()->optional()->url(),
            'is_active' => true,
        ];
    }
}
