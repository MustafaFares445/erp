<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerQuotationRequest;
use App\Models\CustomerQuotationRequestLine;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerQuotationRequestLine>
 */
final class CustomerQuotationRequestLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_quotation_request_id' => CustomerQuotationRequest::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'requested_quantity' => fake()->randomFloat(3, 1, 20),
            'customer_note' => fake()->optional()->sentence(),
            'sort_order' => 0,
        ];
    }
}
