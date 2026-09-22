<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a business constraint's value should be read and rendered.
 *
 * The unit is a property of the constraint's definition, not of its stored
 * value, so it lives on {@see BusinessConstraintKey} rather than in the
 * database — see {@see BusinessConstraintKey::unit()}.
 */
enum BusinessConstraintUnit: string
{
    case Percent = 'percent';
    case Days = 'days';
    case Currency = 'currency';

    public function label(): string
    {
        return __('admin.constraints.units.'.$this->value);
    }

    /**
     * The suffix a form field shows after the input.
     *
     * Currency returns null: the code varies per constraint and is resolved
     * from the currency catalogue at render time, not from the unit.
     */
    public function suffix(): ?string
    {
        return match ($this) {
            self::Percent => '%',
            self::Days => self::daysSuffix(),
            self::Currency => null,
        };
    }

    private static function daysSuffix(): string
    {
        return __('admin.constraints.units.days_suffix');
    }
}
