<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceKind;
use App\Models\CustomerProfile;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EquipmentCalibration> */
final class EquipmentCalibrationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $customer = CustomerProfile::factory()->create();
        $unit = SerializedInventoryUnit::factory()->create();
        $record = MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'maintenance_kind' => MaintenanceKind::Calibration,
        ]);

        return [
            'maintenance_record_id' => $record->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'started_at' => now(),
        ];
    }

    public function passed(): static
    {
        return $this->state(fn (): array => [
            'calibrated_at' => now(),
            'result' => CalibrationResult::Passed,
            'next_calibration_due_on' => now()->addMonths(6)->toDateString(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'calibrated_at' => now(),
            'result' => CalibrationResult::Failed,
            'failure_reason' => 'Out of tolerance',
        ]);
    }
}
