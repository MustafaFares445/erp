<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Defaults to {@see UserType::Admin} because the application currently
     * exposes only the admin panel and every pre-existing test assumes its
     * factory user can reach it; non-admin scenarios use the explicit
     * `customer()` / `employee()` states below.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'user_type' => UserType::Admin,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_type' => UserType::Admin,
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_type' => UserType::Customer,
        ]);
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_type' => UserType::Employee,
        ]);
    }
}
