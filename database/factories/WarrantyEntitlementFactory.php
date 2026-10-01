<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SerializedCustodyType;
use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyEntitlementState;
use App\Enums\WarrantyStartTrigger;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\WarrantyEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WarrantyEntitlement> */
final class WarrantyEntitlementFactory extends Factory
{
    public function definition(): array
    {
        $customer = CustomerProfile::factory();

        return [
            'serialized_inventory_unit_id' => SerializedInventoryUnit::factory()->state([
                'custody_type' => SerializedCustodyType::Customer,
            ]),
            'customer_id' => $customer,
            'warranty_policy_id' => null,
            'source_shipment_id' => null,
            'state' => WarrantyEntitlementState::Active,
            'policy_name' => 'Standard customer warranty',
            'duration_value' => 12,
            'duration_unit' => WarrantyDurationUnit::Months,
            'start_trigger' => WarrantyStartTrigger::ConfirmedDelivery,
            'covers_parts' => true,
            'covers_labour' => true,
            'covers_travel' => false,
            'covers_consumables' => false,
            'covers_third_party' => false,
            'transferable' => false,
            'replacement_rule' => 'remaining_original_term',
            'starts_on' => today(),
            'expires_on' => today()->addYear(),
        ];
    }
}
