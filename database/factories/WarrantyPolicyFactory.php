<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyStartTrigger;
use App\Models\WarrantyPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WarrantyPolicy> */
final class WarrantyPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => mb_strtoupper(fake()->unique()->bothify('WAR-####')),
            'name' => fake()->words(3, true),
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
            'is_active' => true,
        ];
    }
}
