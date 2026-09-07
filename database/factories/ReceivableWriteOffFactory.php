<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReceivableWriteOff> */
final class ReceivableWriteOffFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'write_off_number' => fake()->unique()->bothify('WO-######'),
            'status' => WriteOffStatus::Draft,
            'customer_id' => CustomerProfile::factory(),
            'invoice_id' => Invoice::factory(),
            'amount_minor' => 1000,
            'tax_amount_minor' => 0,
            'reason_category' => WriteOffReason::Other,
            'reason' => fake()->sentence(),
            'recorded_by' => User::factory(),
            'fiscal_period_id' => FiscalPeriod::factory(),
        ];
    }
}
