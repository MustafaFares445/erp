<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\TaxRecognitionEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxRecognitionEntry> */
final class TaxRecognitionEntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tax_date' => today(),
            'direction' => 'deferred_output',
            'tax_type' => 'Sales tax deferred until collection',
            'tax_amount' => fake()->randomFloat(2, 1, 1_000),
            'source_type' => Invoice::class,
            'source_id' => Invoice::factory(),
        ];
    }
}
