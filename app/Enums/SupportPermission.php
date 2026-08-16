<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksSupportPermissions;
use Database\Seeders\SupportPermissionSeeder;

/**
 * Canonical `support.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see SupportPermissionSeeder}
 * and by {@see ChecksSupportPermissions}. Deliberately has no
 * `fixedRoleNames()` method of its own — only {@see DashboardRole::fixedRoleNames()}
 * is ever consulted for the cross-module admin-bypass check.
 *
 * @see /specs/016-support-maintenance-dashboard/contracts/permissions.md
 */
enum SupportPermission: string
{
    case TicketView = 'support.ticket.view';
    case TicketManage = 'support.ticket.manage';
    case TicketAssign = 'support.ticket.assign';
    case TicketWork = 'support.ticket.work';
    case TicketMessage = 'support.ticket.message';
    case TicketSettlePayment = 'support.ticket.settle-payment';
    case RecordRestore = 'support.record.restore';
    case SlaPolicyView = 'support.sla-policy.view';
    case SlaPolicyManage = 'support.sla-policy.manage';
    case MaintenanceRequestView = 'support.maintenance-request.view';
    case MaintenanceRequestManage = 'support.maintenance-request.manage';
    case ServiceRecordView = 'support.service-record.view';
    case ServiceRecordManage = 'support.service-record.manage';
    case ServiceRecordExecute = 'support.service-record.execute';
    case PartsConsume = 'support.parts.consume';
    case PartsReverse = 'support.parts.reverse';
    case ReportView = 'support.report.view';
    case AuditView = 'support.audit.view';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
