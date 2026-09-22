<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerReturnRequest;
use App\Models\CustomerReturnRequestLine;
use App\Models\InventoryOperationLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerReturnRequestLine>
 */
final class CustomerReturnRequestLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_return_request_id' => CustomerReturnRequest::factory(),
            'original_inventory_operation_line_id' => InventoryOperationLine::factory(),
            'requested_quantity' => fake()->randomFloat(2, 1, 5),
            'customer_note' => fake()->optional()->sentence(),
            'sort_order' => 0,
        ];
    }
}
