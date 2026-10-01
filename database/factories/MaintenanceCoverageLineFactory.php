<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyLineCategory;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceCoverageLine> */
final class MaintenanceCoverageLineFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->numberBetween(1000, 25000);
        $coveragePercent = [0, 50, 100][fake()->numberBetween(0, 2)];
        $covered = (int) round($amount * $coveragePercent / 100);

        return [
            'maintenance_record_id' => MaintenanceRecord::factory(),
            'category' => WarrantyLineCategory::Other,
            'description' => fake()->sentence(3),
            'amount_minor' => $amount,
            'coverage_percent' => $coveragePercent,
            'covered_amount_minor' => $covered,
            'customer_amount_minor' => $amount - $covered,
            'coverage_source' => $coveragePercent > 0 ? WarrantyCoverageSource::SellerWarranty : WarrantyCoverageSource::CustomerPaid,
        ];
    }
}
