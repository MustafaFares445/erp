<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CustomerProfileChangeRequestStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerProfileChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfileChangeRequest>
 */
final class CustomerProfileChangeRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => CustomerProfile::factory(),
            'requested_by_user_id' => null,
            'status' => CustomerProfileChangeRequestStatus::Pending,
            'requested_changes' => ['company_name' => fake()->company()],
            'reason' => fake()->sentence(),
            'source_channel' => 'dashboard',
        ];
    }
}
