<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DepositApplicationIssue;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepositApplicationIssue>
 */
final class DepositApplicationIssueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'error_message' => fake()->sentence(),
            'occurred_at' => now(),
        ];
    }
}
