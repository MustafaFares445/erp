<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryExportType;
use App\Models\InventoryExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryExport>
 */
class InventoryExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(array_map(
                static fn (InventoryExportType $type): string => $type->value,
                InventoryExportType::cases(),
            )),
            'filters' => [],
            'file_path' => null,
            'status' => 'queued',
            'failure_reason' => null,
            'created_by' => User::factory(),
            'completed_at' => null,
        ];
    }
}
