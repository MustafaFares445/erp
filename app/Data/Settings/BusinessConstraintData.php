<?php

declare(strict_types=1);

namespace App\Data\Settings;

use App\Enums\BusinessConstraintEnforcement;
use App\Enums\BusinessConstraintKey;
use DomainException;
use Spatie\LaravelData\Data;

/**
 * One constraint as it currently stands: its declared identity, its effective
 * value, and whether that value is the owner's or the catalogue's default.
 *
 * `isDefault` matters to the settings UI — an owner should be able to see at a
 * glance which numbers they have actually decided and which are simply what
 * shipped.
 */
final class BusinessConstraintData extends Data
{
    /** @param list<int>|null $list */
    public function __construct(
        public BusinessConstraintKey $key,
        public ?float $value,
        public ?array $list,
        public ?BusinessConstraintEnforcement $enforcement,
        public bool $isDefault,
    ) {}

    /**
     * The scalar value, for a constraint that is required to have one.
     *
     * @throws DomainException when read off a list constraint, or off a
     *                         nullable one that is unset
     */
    public function requireValue(): float
    {
        if ($this->value === null) {
            throw new DomainException("The {$this->key->value} constraint has no value set.");
        }

        return $this->value;
    }

    /**
     * @return list<int>
     *
     * @throws DomainException when read off a scalar constraint
     */
    public function requireList(): array
    {
        if ($this->list === null) {
            throw new DomainException("The {$this->key->value} constraint is not a list.");
        }

        return $this->list;
    }

    /**
     * @throws DomainException when read off a policy constraint, which has
     *                         nothing to enforce
     */
    public function requireEnforcement(): BusinessConstraintEnforcement
    {
        if (! $this->enforcement instanceof BusinessConstraintEnforcement) {
            throw new DomainException("The {$this->key->value} constraint has no enforcement mode.");
        }

        return $this->enforcement;
    }

    /** Whether this constraint is configured to police anything at all. */
    public function isActive(): bool
    {
        return $this->value !== null || $this->list !== null;
    }
}
