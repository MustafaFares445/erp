<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChartAccount;
use App\Models\Expense;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Expense> */
final class ExpenseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 10_000);
        $tax = round($subtotal * 0.05, 2);

        return [
            'expense_number' => fake()->unique()->bothify('EXP-#######'),
            'supplier_id' => Supplier::factory(),
            'expense_account_id' => ChartAccount::factory(),
            'expense_date' => today(),
            'due_date' => today()->addDays(30),
            'merchant_name' => fake()->company(),
            'description' => fake()->sentence(),
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'total_amount' => $subtotal + $tax,
            'amount_paid' => 0,
            'status' => 'draft',
        ];
    }
}
