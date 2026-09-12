<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationEventKey: string
{
    case InvoiceIssued = 'invoice.issued';
    case PaymentReceived = 'payment.received';
    case QuotationDecided = 'quotation.decided';
    case QuotationExpired = 'quotation.expired';
    case TaskAssigned = 'task.assigned';
    case VisitDue = 'visit.due';
    case TicketUpdated = 'ticket.updated';
    case SlaAtRisk = 'sla.at_risk';
    case StockLow = 'stock.low';
    case LotExpiring = 'lot.expiring';
    case ApprovalPending = 'approval.pending';
    case InventoryReservationExpired = 'inventory.reservation.expired';
    case LeadConverted = 'lead.converted';
    case CampaignCompleted = 'campaign.completed';
    case MaintenanceRecordBilled = 'maintenance.billed';
    case MaintenanceDue = 'maintenance.due';
    case InvoiceOverdue7 = 'invoice.overdue.7';
    case InvoiceOverdue30 = 'invoice.overdue.30';
    case InvoiceOverdue60 = 'invoice.overdue.60';
    case PurchaseOrderReadyForAllocation = 'purchase_order.ready_for_allocation';
    case PurchaseOrderDraftBillReady = 'purchase_order.draft_bill_ready';
}
