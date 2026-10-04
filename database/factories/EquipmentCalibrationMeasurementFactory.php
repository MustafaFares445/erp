<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CalibrationMeasurementResult;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentCalibrationMeasurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EquipmentCalibrationMeasurement> */
final class EquipmentCalibrationMeasurementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'equipment_calibration_id' => EquipmentCalibration::factory(),
            'measurement_key' => 'furnace_temperature',
            'label' => 'Furnace temperature',
            'expected_value' => '950.0000',
            'minimum_value' => '940.0000',
            'maximum_value' => '960.0000',
            'unit' => '°C',
            'is_required' => true,
            'result' => CalibrationMeasurementResult::Pending,
            'sort_order' => 0,
        ];
    }
}
