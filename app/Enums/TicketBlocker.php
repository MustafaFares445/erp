<?php

declare(strict_types=1);

namespace App\Enums;

enum TicketBlocker: string
{
    case None = 'none';
    case TriageRequired = 'triage_required';
    case DiagnosticPayment = 'diagnostic_payment';
    case Assignment = 'assignment';
    case CustomerResponse = 'customer_response';
    case MaintenanceAction = 'maintenance_action';
    case QuotationApproval = 'quotation_approval';
    case SlaBreach = 'sla_breach';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::None => __('No blocker'),
            self::TriageRequired => __('Triage required'),
            self::DiagnosticPayment => __('Diagnostic payment'),
            self::Assignment => __('Assignment required'),
            self::CustomerResponse => __('Waiting for customer'),
            self::MaintenanceAction => __('Maintenance action required'),
            self::QuotationApproval => __('Quotation approval'),
            self::SlaBreach => __('SLA breach'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::None => 'success',
            self::SlaBreach, self::Cancelled => 'danger',
            self::Assignment, self::TriageRequired => 'info',
            default => 'warning',
        };
    }
}
