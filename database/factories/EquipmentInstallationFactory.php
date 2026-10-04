<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommissioningStatus;
use App\Enums\CustomerAcceptanceStatus;
use App\Enums\MaintenanceKind;
use App\Models\CustomerProfile;
use App\Models\EquipmentInstallation;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EquipmentInstallation> */
final class EquipmentInstallationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $customer = CustomerProfile::factory()->create();
        $unit = SerializedInventoryUnit::factory()->create();
        $record = MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'maintenance_kind' => MaintenanceKind::Installation,
        ]);

        return [
            'maintenance_record_id' => $record->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'commissioning_status' => CommissioningStatus::Pending,
            'customer_acceptance_status' => CustomerAcceptanceStatus::Pending,
        ];
    }

    public function installed(): static
    {
        return $this->state(fn (): array => ['installed_at' => now()]);
    }

    public function commissioned(): static
    {
        return $this->installed()->state(fn (): array => [
            'commissioning_status' => CommissioningStatus::Passed,
            'commissioned_at' => now(),
        ]);
    }
}
