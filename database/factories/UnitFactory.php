<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'name_ar' => null,
            'symbol' => mb_strtoupper(fake()->unique()->lexify('???')),
            'allows_decimal' => true,
            'is_active' => true,
        ];
    }

    /** A countable unit, such as Each — the unit a machine is measured in. */
    public function whole(): self
    {
        return $this->state(['allows_decimal' => false]);
    }

    /** A weight unit, for the net weight a grain variant is measured by. */
    public function weight(): self
    {
        return $this->state([
            'name' => 'Kilogram',
            'name_ar' => 'كيلوغرام',
            'symbol' => mb_strtoupper(fake()->unique()->lexify('KG?')),
            'allows_decimal' => true,
        ]);
    }
}
