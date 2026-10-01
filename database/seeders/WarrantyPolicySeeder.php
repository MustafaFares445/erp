<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\WarrantyDurationUnit;
use App\Enums\WarrantyStartTrigger;
use App\Models\WarrantyPolicy;
use Illuminate\Database\Seeder;

final class WarrantyPolicySeeder extends Seeder
{
    public function run(): void
    {
        WarrantyPolicy::query()->firstOrCreate(
            ['code' => 'STANDARD-12M'],
            [
                'name' => 'Standard Equipment Warranty',
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
            ],
        );
    }
}
