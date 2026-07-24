<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransferStatus;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransfer>
 */
final class StockTransferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => Warehouse::factory(),
            'transfer_number' => null,
            'notes' => fake()->optional()->sentence(),
            'status' => TransferStatus::Draft,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'transfer_number' => 'TRF-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999_999), 6, '0', STR_PAD_LEFT),
            'status' => TransferStatus::Confirmed,
        ]);
    }
}
