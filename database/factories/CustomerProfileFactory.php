<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
final class CustomerProfileFactory extends Factory
{
    /** @var list<array{city: string, latitude: float, longitude: float}> */
    private const array Locations = [
        ['city' => 'Dubai', 'latitude' => 25.2048, 'longitude' => 55.2708],
        ['city' => 'Abu Dhabi', 'latitude' => 24.4539, 'longitude' => 54.3773],
        ['city' => 'Sharjah', 'latitude' => 25.3463, 'longitude' => 55.4209],
        ['city' => 'Ajman', 'latitude' => 25.4052, 'longitude' => 55.5136],
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $location = self::Locations[fake()->numberBetween(0, count(self::Locations) - 1)];

        return [
            'user_id' => User::factory()->customer(),
            'customer_code' => fake()->unique()->bothify('CUST-####'),
            'company_name' => fake()->company(),
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => $location['city'].', United Arab Emirates',
            'country' => 'AE',
            'city' => $location['city'],
            'latitude' => $location['latitude'],
            'longitude' => $location['longitude'],
            'accountant_name' => null,
            'accountant_phone' => null,
            'accountant_email' => null,
            'contact_is_self' => true,
            'contact_name' => null,
            'contact_phone' => null,
            'contact_email' => null,
            'is_active' => true,
        ];
    }
}
