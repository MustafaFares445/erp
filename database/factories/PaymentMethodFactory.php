<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChartAccount;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/** @extends Factory<PaymentMethod> */
final class PaymentMethodFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        if (! is_string($name)) {
            throw new RuntimeException('Expected words() with asText=true to return a string.');
        }

        return [
            'name' => $name.' transfer',
            'type' => 'bank_transfer',
            'chart_account_id' => ChartAccount::factory(),
            'is_active' => true,
            'requires_proof' => false,
        ];
    }
}
