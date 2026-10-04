<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExternalRepairStatus;
use App\Enums\SerializedCustodyType;
use App\Models\CustomerProfile;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MaintenanceExternalRepair> */
final class MaintenanceExternalRepairFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $customer = CustomerProfile::factory()->create();
        $unit = SerializedInventoryUnit::factory()->create([
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_id' => $customer->getKey(),
        ]);
        $record = MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
        ]);

        return [
            'maintenance_record_id' => $record->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'supplier_id' => Supplier::factory(),
            'status' => ExternalRepairStatus::Requested,
            'requested_at' => now(),
        ];
    }
}
