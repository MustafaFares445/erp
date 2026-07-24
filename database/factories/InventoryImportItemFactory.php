<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryImportItemStatus;
use App\Models\InventoryImportItem;
use App\Models\InventoryImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryImportItem>
 */
class InventoryImportItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rowNumber = fake()->unique()->numberBetween(2, 100_000);

        return [
            'inventory_import_run_id' => InventoryImportRun::factory(),
            'row_number' => $rowNumber,
            'idempotency_key' => hash('sha256', fake()->uuid().":{$rowNumber}"),
            'payload' => [
                'sku' => mb_strtoupper(fake()->bothify('SKU-####')),
                'product_name' => fake()->words(3, true),
                'variant_name' => fake()->words(2, true),
            ],
            'status' => InventoryImportItemStatus::Valid,
        ];
    }
}
