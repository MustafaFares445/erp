<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PurchaseSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseSetting>
 */
final class PurchaseSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Matches the column default: everything requires explicit approval
            // until a threshold is deliberately set.
            'approval_threshold_amount' => '0.00',
            'approval_threshold_currency' => 'AED',
        ];
    }

    public function threshold(string $amount, string $currency = 'AED'): self
    {
        return $this->state(fn (): array => [
            'approval_threshold_amount' => $amount,
            'approval_threshold_currency' => $currency,
        ]);
    }
}
