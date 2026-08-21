<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountElement;
use App\Models\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountType>
 */
final class AccountTypeFactory extends Factory
{
    /**
     * Claims the first element that has no row yet, so repeated
     * `AccountType::factory()->create()` calls produce the five distinct types
     * rather than colliding on the unique `name`. A sixth call has nothing left
     * to claim and fails on that index — correctly, because only five
     * accounting elements exist (FR-002).
     *
     * `normal_balance` is always derived from the element rather than randomised:
     * the pairing is fixed by double-entry accounting, so a factory able to
     * produce a credit-normal Asset would let tests pass against data the
     * application can never hold.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => self::unclaimedElement(...),
            'normal_balance' => fn (array $attributes): string => self::elementOf($attributes['name'] ?? null)
                ->normalBalance()
                ->value,
        ];
    }

    public function element(AccountElement $element): self
    {
        return $this->state(fn (): array => [
            'name' => $element,
            'normal_balance' => $element->normalBalance(),
        ]);
    }

    /**
     * The row for a given element, reused when it already exists.
     *
     * Tests and seeders both need "the Asset type" rather than "a new Asset
     * type", and there is only ever one of each.
     */
    public static function existingOrNew(AccountElement $element): AccountType
    {
        $existing = AccountType::query()->where('name', $element->value)->first();

        return $existing ?? AccountType::factory()->element($element)->create();
    }

    private static function unclaimedElement(): AccountElement
    {
        /** @var list<string> $taken */
        $taken = AccountType::query()->pluck('name')->all();

        foreach (AccountElement::cases() as $element) {
            if (! in_array($element->value, $taken, true)) {
                return $element;
            }
        }

        return AccountElement::Asset;
    }

    /**
     * The element a partially-built attribute set names, defaulting to Asset when
     * it names none — the same default {@see self::unclaimedElement()} falls back
     * to.
     */
    private static function elementOf(mixed $name): AccountElement
    {
        if ($name instanceof AccountElement) {
            return $name;
        }

        return is_string($name) ? AccountElement::from($name) : AccountElement::Asset;
    }
}
