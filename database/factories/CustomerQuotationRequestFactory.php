<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerQuotationRequestStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerQuotationRequest>
 */
final class CustomerQuotationRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_number' => 'QR-'.mb_str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => CustomerProfile::factory(),
            'notes' => fake()->optional()->sentence(),
            'status' => CustomerQuotationRequestStatus::Submitted,
            'submitted_at' => now(),
            'source_channel' => 'dashboard',
        ];
    }
}
