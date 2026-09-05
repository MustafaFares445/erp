<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CreditNoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNoteLine>
 */
class CreditNoteLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 5);
        $unitPrice = fake()->randomFloat(2, 10, 100);

        return [
            'description' => fake()->words(3, true),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tax_amount' => 0,
            'line_total' => round($quantity * $unitPrice, 2),
            'sort_order' => 0,
        ];
    }
}
