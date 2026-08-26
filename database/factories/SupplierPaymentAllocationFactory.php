<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Bill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierPaymentAllocation> */
final class SupplierPaymentAllocationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'supplier_payment_id' => SupplierPayment::factory(),
            'bill_id' => Bill::factory(),
            'amount' => fake()->randomFloat(2, 1, 1_000),
        ];
    }
}
