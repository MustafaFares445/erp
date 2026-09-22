<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerReturnRequestStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReturnRequest>
 */
final class CustomerReturnRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_number' => 'RR-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => CustomerProfile::factory(),
            'original_inventory_operation_id' => InventoryOperation::factory()->delivery(),
            'reason' => fake()->optional()->sentence(),
            'status' => CustomerReturnRequestStatus::Submitted,
            'submitted_at' => now(),
            'source_channel' => 'dashboard',
        ];
    }
}
