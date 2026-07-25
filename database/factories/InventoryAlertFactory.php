<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryAlertSeverity;
use App\Enums\InventoryAlertType;
use App\Models\InventoryAlert;
use App\Models\InventoryStock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAlert>
 */
final class InventoryAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => InventoryAlertType::LowStock,
            'subject_type' => InventoryStock::class,
            'subject_id' => InventoryStock::factory(),
            'message' => fake()->sentence(),
            'severity' => InventoryAlertSeverity::Warning,
            'context' => null,
            'resolved_at' => null,
        ];
    }
}
