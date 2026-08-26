<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTerm>
 */
final class PaymentTermFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // fake()->randomElement() returns mixed per its stub, and this value
        // must stay a genuine int (it is stored in `due_days` and used to
        // build `name`). numberBetween() as the index instead keeps Faker's
        // uniqueness guarantee — needed since `name` is a unique column — and
        // returns a real int.
        $choices = [15, 30, 45, 60, 90];
        $days = $choices[fake()->unique()->numberBetween(0, count($choices) - 1)];

        return [
            'name' => "Net {$days}",
            'due_days' => $days,
            'grace_days' => fake()->numberBetween(0, 7),
            'discount_percent' => null,
            'is_default' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
