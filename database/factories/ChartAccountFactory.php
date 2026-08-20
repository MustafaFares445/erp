<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountElement;
use App\Models\AccountType;
use App\Models\ChartAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartAccount>
 */
final class ChartAccountFactory extends Factory
{
    /**
     * Reuses whichever account type already exists rather than creating one per
     * account: there are only ever five type rows (FR-002), so a factory that
     * made a new one each time would collide on the unique `name` the moment a
     * test created a sixth account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_type_id' => self::anyAccountTypeId(...),
            'parent_id' => null,
            'code' => (string) fake()->unique()->numberBetween(1_000, 999_999),
            'name' => fake()->words(3, true),
            'is_postable' => true,
            'is_active' => true,
        ];
    }

    /**
     * A parent account, which may never be a posting target (FR-007).
     */
    public function header(): self
    {
        return $this->state(fn (): array => ['is_postable' => false]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function ofElement(AccountElement $element): self
    {
        return $this->state(fn (): array => [
            'account_type_id' => AccountTypeFactory::existingOrNew($element)->getKey(),
        ]);
    }

    private static function anyAccountTypeId(): int
    {
        $existing = AccountType::query()->orderBy('id')->value('id');

        return is_numeric($existing)
            ? (int) $existing
            : AccountTypeFactory::existingOrNew(AccountElement::Asset)->id;
    }
}
