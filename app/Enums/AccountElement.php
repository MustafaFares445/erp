<?php

declare(strict_types=1);

namespace App\Enums;

use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The five accounting elements, which are fixed by double-entry accounting
 * itself rather than by this application.
 *
 * `account_types` holds exactly one seeded row per case (FR-002) and has no
 * Filament resource: a sixth element is meaningless, and renaming one would
 * break the normal-balance semantics every balance calculation depends on
 * (research.md R-007). {@see ChartOfAccountsSeeder} derives each row's
 * `normal_balance` from {@see self::normalBalance()}, so the pairing is
 * declared once.
 *
 * @see /specs/018-chart-of-accounts-journals/data-model.md §2
 */
enum AccountElement: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }

    public function label(): string
    {
        return __('admin.accounting.element.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $element): string => $element->value, self::cases());
    }
}
