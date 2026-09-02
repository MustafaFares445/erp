<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditNoteReason;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_note_number' => fake()->unique()->bothify('CN-#######'),
            'customer_id' => CustomerProfile::factory(),
            'reason' => fake()->sentence(),
            'reason_category' => CreditNoteReason::Other,
            'issue_date' => today(),
            'subtotal' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'status' => 'draft',
        ];
    }
}
