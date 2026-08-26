<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierPayment> */
final class SupplierPaymentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'supplier_payment_number' => fake()->unique()->bothify('SPAY-#######'),
            'supplier_id' => Supplier::factory(),
            'payment_method_id' => PaymentMethod::factory(),
            'amount' => fake()->randomFloat(2, 1, 10_000),
            'payment_date' => today(),
            'reference' => fake()->optional()->bothify('BANK-########'),
            'status' => 'draft',
        ];
    }
}
