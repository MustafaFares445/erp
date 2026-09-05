<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Inventory\InventoryLotReconciliationService;

/**
 * The period-close checklist (WP-2.5, GAP-MW-18): every check the gate runs
 * before a period may be closed.
 *
 * The first five are mandatory — each delegates to the service that already
 * owns the figure it checks, never recomputing it by a different rule (XC-04):
 * {@see FinancialReportService::trialBalance()},
 * {@see AccountsReceivableService::reconciliation()},
 * {@see AccountsPayableService::summary()},
 * {@see TaxRegisterService::reconciliation()}, and a
 * fresh {@see InventoryLotReconciliationService} run.
 * The last two are advisory housekeeping signals that never block a close.
 *
 * @see /ERP_REMEDIATION_PLAN.md WP-2.5
 */
enum PeriodCloseCheck: string
{
    case TrialBalanceBalances = 'trial_balance_balances';
    case ReceivablesAgreeToControlAccount = 'receivables_agree_to_control_account';
    case PayablesAgreeToControlAccount = 'payables_agree_to_control_account';
    case TaxRegisterAgreesToTaxAccounts = 'tax_register_agrees_to_tax_accounts';
    case StockLedgerReconciles = 'stock_ledger_reconciles';
    case NoDraftJournalEntriesInPeriod = 'no_draft_journal_entries_in_period';
    case NoUnpostedPaymentsInPeriod = 'no_unposted_payments_in_period';

    public function isMandatory(): bool
    {
        return match ($this) {
            self::TrialBalanceBalances,
            self::ReceivablesAgreeToControlAccount,
            self::PayablesAgreeToControlAccount,
            self::TaxRegisterAgreesToTaxAccounts,
            self::StockLedgerReconciles => true,
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
