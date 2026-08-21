<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksAccountingPermissions;
use Database\Seeders\AccountingPermissionSeeder;

/**
 * Canonical `accounting.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see AccountingPermissionSeeder} and by
 * {@see ChecksAccountingPermissions}. Deliberately has no `fixedRoleNames()`
 * method of its own — only {@see DashboardRole::fixedRoleNames()} is ever
 * consulted for the cross-module admin-bypass check.
 *
 * Three separations in this catalogue are load-bearing (FR-040), not
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
 *
 * @see /specs/018-chart-of-accounts-journals/contracts/permissions.md
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
    case LedgerView = 'accounting.ledger.view';
    case AuditView = 'accounting.audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
