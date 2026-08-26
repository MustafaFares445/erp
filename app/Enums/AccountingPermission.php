<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksAccountingPermissions;
use App\Services\Accounting\JournalPostingService;
use Database\Seeders\AccountingPermissionSeeder;

/**
 * Canonical `accounting.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see AccountingPermissionSeeder} and by
 * {@see ChecksAccountingPermissions}. Deliberately has no `fixedRoleNames()`
 * method of its own — only {@see DashboardRole::fixedRoleNames()} is ever
 * consulted for the cross-module admin-bypass check.
 *
 * Four separations in this catalogue are load-bearing (FR-040), not
 * granularity for its own sake:
 *
 * - {@see self::JournalEntryManage} does not imply {@see self::JournalEntryPost}
 *   — recording a draft and committing it to the ledger are different acts.
 * - {@see self::JournalEntryPost} does not imply
 *   {@see self::JournalEntryReverse} — reversal changes the meaning of already
 *   reported history; posting only adds to it.
 * - {@see self::FiscalPeriodManage} does not imply
 *   {@see self::FiscalPeriodClose} — creating next year's periods is routine;
 *   declaring a period final is not.
 * - {@see self::JournalEntryPostFromSource} does not imply
 *   {@see self::JournalEntryManage} or {@see self::JournalEntryPost}. Added by
 *   spec 019 (ADR 0008) for the three sales-document posting events: an
 *   invoice, payment, or credit note author needs the ledger entry *their own
 *   document* produces to go through, but granting them `JournalEntryManage`
 *   would additionally unlock the Journal Entries dashboard page's free-form
 *   "New" action — a manual entry with no source at all. `JournalEntryPolicy`
 *   tells the two apart by whether the entry being created or posted carries a
 *   `source` (see {@see self::JournalEntryPostFromSource}'s use in that
 *   policy), so a role holding only this permission can post through
 *   {@see JournalPostingService} on a document's
 *   behalf and can reach no other accounting page or action.
 * - {@see self::ReportView} is implied by no other permission, and specifically
 *   not by {@see self::LedgerView}: `ledger.view` grants one account's own
 *   posted lines from the Chart of Accounts page, while `report.view` grants
 *   the whole book in aggregate, in a form built for circulation outside the
 *   system (spec 020, ADR 0009).
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/permissions.md
 * @see /specs/019-sales-lifecycle-payments-credits/contracts/permissions.md §4
 * @see /specs/020-accounting-financial-reports/contracts/permissions.md §2
 */
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
    case TaxView = 'accounting.tax.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
