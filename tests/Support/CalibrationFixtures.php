<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\CalibrationResult;
use App\Enums\MaintenanceKind;
use App\Enums\SerializedCustodyType;
use App\Models\CustomerProfile;
use App\Models\EquipmentCalibration;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\EquipmentCalibrationService;

/** Shared builders for the equipment calibration tests. */
final class CalibrationFixtures
{
    /** @return array{0: CustomerProfile, 1: SerializedInventoryUnit, 2: MaintenanceRecord} */
    public static function scenario(): array
    {
        $customer = CustomerProfile::factory()->create();
        $unit = SerializedInventoryUnit::factory()->create([
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_id' => $customer->getKey(),
        ]);
        $record = MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'maintenance_kind' => MaintenanceKind::Calibration,
        ]);

        return [$customer, $unit, $record];
    }

    /** @return list<array<string, mixed>> */
    public static function measurements(): array
    {
        return [
            ['key' => 'furnace_temperature', 'label' => 'Furnace temperature', 'expected_value' => '950', 'minimum_value' => '940', 'maximum_value' => '960', 'unit' => 'C'],
            ['key' => 'uniformity', 'label' => 'Temperature uniformity', 'maximum_value' => '5', 'unit' => 'C', 'is_required' => false],
        ];
    }

    public static function started(MaintenanceRecord $record, User $actor): EquipmentCalibration
    {
        return app(EquipmentCalibrationService::class)->start($record, $actor, ['measurements' => self::measurements()]);
    }

    /** Records in-tolerance values for the required measurement (and optionally the optional one). */
    public static function measure(EquipmentCalibration $calibration, User $actor, string $temperature = '943'): void
    {
        app(EquipmentCalibrationService::class)->recordMeasurement($calibration, 'furnace_temperature', $temperature, $actor);
    }

    /** Drives a calibration through the requested stage (1=started, 2=completed, 3=certificate issued). */
    public static function calibration(MaintenanceRecord $record, User $actor, int $stage = 1): EquipmentCalibration
    {
        $service = app(EquipmentCalibrationService::class);
        $calibration = self::started($record, $actor);

        if ($stage >= 2) {
            self::measure($calibration, $actor);
            $service->complete($calibration, $actor, CalibrationResult::Passed, null, now()->addMonths(6));
        }

        if ($stage >= 3) {
            $service->issueCertificate($calibration->refresh(), $actor, 'CAL-0001', now()->addYear());
        }

        return $calibration->fresh();
    }
}
