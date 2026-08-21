<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerDeliveryAddress;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerDeliveryAddress>
 */
final class CustomerDeliveryAddressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'label' => 'Primary delivery address',
            'address' => fake()->streetAddress(),
            'country' => 'AE',
            'city' => fake()->city(),
            'latitude' => fake()->latitude(24, 26),
            'longitude' => fake()->longitude(54, 56),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->phoneNumber(),
            'is_active' => true,
            'is_default' => false,
        ];
    }
}
