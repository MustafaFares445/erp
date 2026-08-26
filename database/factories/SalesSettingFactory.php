<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChartAccount;
use App\Models\SalesSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesSetting>
 */
final class SalesSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'default_tax_percent' => '0.00',
            'default_quotation_validity_days' => 30,
        ];
    }

    /**
     * All five posting accounts configured and postable, for tests that need
     * a working posting path without repeating the setup.
     */
    public function withPostingAccounts(): self
    {
        return $this->state(fn (): array => [
            'receivable_account_id' => ChartAccount::factory(),
            'revenue_account_id' => ChartAccount::factory(),
            'deferred_tax_account_id' => ChartAccount::factory(),
            'tax_payable_account_id' => ChartAccount::factory(),
            'customer_deposits_account_id' => ChartAccount::factory(),
        ]);
    }
}
