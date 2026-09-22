<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use App\Models\BusinessConstraint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessConstraint>
 */
class BusinessConstraintFactory extends Factory
{
    /**
     * The default state is the discount ceiling, because it is the constraint
     * most tests want. States are provided rather than free-form attributes
     * because the model refuses any row whose shape disagrees with its key.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => BusinessConstraintKey::MaxDiscountPercent,
            'value' => 25.0,
            'value_json' => null,
            'enforcement' => BusinessConstraintEnforcement::RequireApproval,
            'updated_by' => User::factory(),
        ];
    }

    public function limit(
        BusinessConstraintKey $key,
        ?float $value,
        BusinessConstraintEnforcement $enforcement,
    ): self {
        return $this->state(fn (): array => [
            'key' => $key,
            'value' => $value,
            'value_json' => null,
            'enforcement' => $enforcement,
        ]);
    }

    /** @param  list<int>  $boundaries */
    public function policy(BusinessConstraintKey $key, array $boundaries): self
    {
        return $this->state(fn (): array => [
            'key' => $key,
            'value' => null,
            'value_json' => $boundaries,
            'enforcement' => null,
        ]);
    }
}
