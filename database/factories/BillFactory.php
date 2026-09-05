<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bill> */
final class BillFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10_000);
        $tax = round($subtotal * 0.05, 2);

        return [
            'bill_number' => fake()->unique()->bothify('BILL-#######'),
            'supplier_id' => Supplier::factory(),
            'supplier_reference' => fake()->unique()->bothify('SUP-INV-#######'),
            'expense_account_id' => ChartAccount::factory(),
            'bill_date' => today(),
            'due_date' => today()->addDays(30),
            'description' => fake()->sentence(),
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total_amount' => $subtotal + $tax,
            'amount_paid' => 0,
            'status' => 'draft',
        ];
    }
}
