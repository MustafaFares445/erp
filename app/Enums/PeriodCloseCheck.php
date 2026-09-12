<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The period-close checklist (WP-2.5, GAP-MW-18): every check the gate runs
 * before a period may be closed.
 */
enum PeriodCloseCheck: string
{
    case TrialBalanceBalances = 'trial_balance_balances';
    case ReceivablesAgreeToControlAccount = 'receivables_agree_to_control_account';
    case PayablesAgreeToControlAccount = 'payables_agree_to_control_account';
    case TaxRegisterAgreesToTaxAccounts = 'tax_register_agrees_to_tax_accounts';
    case StockLedgerReconciles = 'stock_ledger_reconciles';
    case InventoryAgreesToControlAccount = 'inventory_agrees_to_control_account';
    case NoDraftJournalEntriesInPeriod = 'no_draft_journal_entries_in_period';
    case NoUnpostedPaymentsInPeriod = 'no_unposted_payments_in_period';

    public function isMandatory(): bool
    {
        return match ($this) {
            self::TrialBalanceBalances,
            self::ReceivablesAgreeToControlAccount,
            self::PayablesAgreeToControlAccount,
            self::TaxRegisterAgreesToTaxAccounts,
            self::StockLedgerReconciles,
            self::InventoryAgreesToControlAccount => true,
            self::NoDraftJournalEntriesInPeriod,
            self::NoUnpostedPaymentsInPeriod => false,
        };
    }

    public function label(): string
    {
        return __('admin.accounting.close_check.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $check): string => $check->value, self::cases());
    }

    /** @return list<self> */
    public static function mandatory(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $check): bool => $check->isMandatory(),
        ));
    }
}
