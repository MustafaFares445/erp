<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
final class InvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10_000);
        $tax = round($subtotal * 0.05, 2);

        return [
            'invoice_number' => fake()->unique()->bothify('INV-#######'),
            'customer_id' => CustomerProfile::factory(),
            'invoice_date' => today(),
            'due_date' => today()->addDays(30),
            'description' => fake()->sentence(),
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total_amount' => $subtotal + $tax,
            'amount_paid' => 0,
            'status' => 'issued',
        ];
    }
}
