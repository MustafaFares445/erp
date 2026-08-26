<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five read-only statements this feature renders over the posted ledger.
 *
 * Deliberately has no `sourcePermission()` analogue: all five are views of the
 * same posted ledger and share one permission, {@see AccountingPermission::ReportView}
 * (research §R7).
 *
 * @see /specs/020-accounting-financial-reports/research.md R7
 */
enum FinancialReportType: string
{
    case TrialBalance = 'trial_balance';
    case GeneralLedger = 'general_ledger';
    case ProfitAndLoss = 'profit_and_loss';
    case BalanceSheet = 'balance_sheet';
    case PostingRegister = 'posting_register';

    public function label(): string
    {
        return __('admin.accounting.report_type.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
