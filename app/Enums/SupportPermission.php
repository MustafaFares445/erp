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
 * @see /Docs/product/ROLES_AND_PERMISSIONS.md
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
    case MaintenanceCostView = 'support.maintenance-cost.view';
    case MaintenanceCostRecord = 'support.maintenance-cost.record';
    case MaintenanceCostBill = 'support.maintenance-cost.bill';
    case MaintenanceScheduleView = 'support.maintenance-schedule.view';
    case MaintenanceScheduleManage = 'support.maintenance-schedule.manage';
    case MaintenanceDiagnosisRecord = 'support.maintenance-diagnosis.record';
    case WarrantyCoverageDecide = 'support.warranty.coverage-decide';
    case WarrantyOverride = 'support.warranty.override';
    case WarrantyPolicyView = 'support.warranty-policy.view';
    case WarrantyPolicyManage = 'support.warranty-policy.manage';
    case WarrantyRecoveryView = 'support.warranty-recovery.view';
    case WarrantyRecoveryManage = 'support.warranty-recovery.manage';
    case TeamView = 'support.team.view';
    case TeamManage = 'support.team.manage';
    case QueueView = 'support.queue.view';
    case QueueManage = 'support.queue.manage';
    case RoutingRuleView = 'support.routing-rule.view';
    case RoutingRuleManage = 'support.routing-rule.manage';
    case TicketRoute = 'support.ticket.route';
    case AutomationView = 'support.automation.view';
    case AutomationManage = 'support.automation.manage';
    case ServiceAppointmentView = 'support.service-appointment.view';
    case ServiceAppointmentManage = 'support.service-appointment.manage';
    case ServiceAppointmentExecute = 'support.service-appointment.execute';
    case Equipment360View = 'support.equipment-360.view';
    case KnowledgeView = 'support.knowledge.view';
    case KnowledgeManage = 'support.knowledge.manage';
    case KnowledgePublish = 'support.knowledge.publish';
    case CsatView = 'support.csat.view';
    case SlaCalendarView = 'support.sla-calendar.view';
    case SlaCalendarManage = 'support.sla-calendar.manage';
    case ServiceLevelView = 'support.service-level.view';
    case ServiceLevelManage = 'support.service-level.manage';
    case EntitlementView = 'support.entitlement.view';
    case EntitlementManage = 'support.entitlement.manage';
    case InstallationView = 'support.installation.view';
    case InstallationManage = 'support.installation.manage';
    case InstallationComplete = 'support.installation.complete';
    case CalibrationView = 'support.calibration.view';
    case CalibrationManage = 'support.calibration.manage';
    case CalibrationComplete = 'support.calibration.complete';
    case LoanView = 'support.loaner.view';
    case LoanManage = 'support.loaner.manage';
    case RmaView = 'support.rma.view';
    case RmaManage = 'support.rma.manage';
    case QualityComplaintView = 'support.quality-complaint.view';
    case QualityComplaintManage = 'support.quality-complaint.manage';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
