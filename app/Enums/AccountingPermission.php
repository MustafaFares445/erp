<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountingPermission: string
{
    case ChartAccountView = 'accounting.chart-account.view';
    case ChartAccountManage = 'accounting.chart-account.manage';
    case FiscalPeriodView = 'accounting.fiscal-period.view';
    case FiscalPeriodManage = 'accounting.fiscal-period.manage';
    case FiscalPeriodClose = 'accounting.fiscal-period.close';
    case JournalEntryView = 'accounting.journal-entry.view';
    case JournalEntryManage = 'accounting.journal-entry.manage';
    case JournalEntryPost = 'accounting.journal-entry.post';
    case JournalEntryReverse = 'accounting.journal-entry.reverse';
    case JournalEntryPostFromSource = 'accounting.journal-entry.post-from-source';
    case LedgerView = 'accounting.ledger.view';
    case AuditView = 'accounting.audit.view';
    case ReportView = 'accounting.report.view';
    case ReceivableView = 'accounting.receivable.view';
    case PayableView = 'accounting.payable.view';
    case BillView = 'accounting.bill.view';
    case BillManage = 'accounting.bill.manage';
    case BillApprove = 'accounting.bill.approve';
    case ExpenseView = 'accounting.expense.view';
    case ExpenseManage = 'accounting.expense.manage';
    case ExpenseApprove = 'accounting.expense.approve';
    case SupplierPaymentManage = 'accounting.supplier-payment.manage';
    case RefundView = 'accounting.refund.view';
    case RefundManage = 'accounting.refund.manage';
    case RefundApprove = 'accounting.refund.approve';
    case RefundPay = 'accounting.refund.pay';
    case TaxView = 'accounting.tax.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
