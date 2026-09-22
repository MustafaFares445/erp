<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What happens when a value crosses a {@see BusinessConstraintKind::Limit}.
 *
 * The three modes are the vocabulary this codebase already speaks, named:
 * {@see self::Block} is the price-floor guard
 * (`PriceResolver::assertAtOrAboveFloor()`), {@see self::RequireApproval} is
 * the recorded-override flow behind it, and {@see self::Warn} is the
 * advisory-only position for a limit an owner wants to observe before
 * enforcing.
 */
enum BusinessConstraintEnforcement: string
{
    case Block = 'block';
    case RequireApproval = 'require_approval';
    case Warn = 'warn';

    public function label(): string
    {
        return __('admin.constraints.enforcement.'.$this->value.'.label');
    }

    public function description(): string
    {
        return __('admin.constraints.enforcement.'.$this->value.'.description');
    }

    public function color(): string
    {
        return match ($this) {
            self::Block => 'danger',
            self::RequireApproval => 'warning',
            self::Warn => 'info',
        };
    }

    /** Whether a breach may proceed when an approved override is supplied. */
    public function acceptsOverride(): bool
    {
        return $this === self::RequireApproval;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
