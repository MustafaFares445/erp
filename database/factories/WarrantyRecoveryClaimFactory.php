<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyRecoveryStatus;
use App\Models\MaintenanceRecord;
use App\Models\WarrantyRecoveryClaim;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WarrantyRecoveryClaim> */
final class WarrantyRecoveryClaimFactory extends Factory
{
    public function definition(): array
    {
        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'coverage_source' => WarrantyCoverageSource::ManufacturerWarranty,
            'counterparty_name' => fake()->company(),
            'status' => WarrantyRecoveryStatus::Draft,
            'currency' => 'AED',
            'claimed_amount_minor' => fake()->numberBetween(1000, 50000),
            'received_amount_minor' => 0,
        ];
    }
}
