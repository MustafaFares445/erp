<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EquipmentLoanStatus;
use App\Enums\SerializedCustodyType;
use App\Models\CustomerProfile;
use App\Models\EquipmentLoan;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EquipmentLoan> */
final class EquipmentLoanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $customer = CustomerProfile::factory()->create();
        $original = SerializedInventoryUnit::factory()->create([
            'custody_type' => SerializedCustodyType::Customer,
            'custody_reference_id' => $customer->getKey(),
        ]);
        $record = MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $original->getKey(),
        ]);

        return [
            'maintenance_record_id' => $record->getKey(),
            'customer_id' => $customer->getKey(),
            'original_serialized_inventory_unit_id' => $original->getKey(),
            'loaner_serialized_inventory_unit_id' => SerializedInventoryUnit::factory(),
            'status' => EquipmentLoanStatus::Reserved,
            'reserved_at' => now(),
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (): array => [
            'status' => EquipmentLoanStatus::Issued,
            'issued_at' => now(),
            'expected_return_at' => now()->addDays(5),
        ]);
    }
}
