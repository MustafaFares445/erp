<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Accounting\AccountBalanceService;

/**
 * Which side of an account increases it.
 *
 * {@see self::sign()} is the single place the reported-balance sign convention
 * lives (FR-036): a balance is computed as `(debits - credits) * sign`, so an
 * account holding its normal balance always reads positive. Consumed by
 * {@see AccountBalanceService}.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §7
 */
enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /**
     * `1` for a debit-normal account, `-1` for a credit-normal one.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Debit => 1,
            self::Credit => -1,
        };
    }

    public function label(): string
    {
        return __('admin.accounting.normal_balance.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $balance): string => $balance->value, self::cases());
    }
}
