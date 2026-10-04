<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallationCheckResult;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentInstallationCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EquipmentInstallationCheck> */
final class EquipmentInstallationCheckFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'equipment_installation_id' => EquipmentInstallation::factory(),
            'check_key' => fake()->unique()->slug(2),
            'label' => fake()->sentence(3),
            'result' => InstallationCheckResult::Pending,
            'sort_order' => 0,
        ];
    }
}
