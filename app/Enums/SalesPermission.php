<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical `sales.*` permission catalogue (guard: `web`).
 *
 * `sales.delivery-note.view` grants only the read surface. Completing,
 * dispatching, or cancelling a delivery still requires Inventory permissions.
 */
enum SalesPermission: string
{
    case SalesSettingView = 'sales.setting.view';
    case SalesSettingManage = 'sales.setting.manage';
    case PaymentTermView = 'sales.payment-term.view';
    case PaymentTermManage = 'sales.payment-term.manage';
    case PaymentMethodView = 'sales.payment-method.view';
    case PaymentMethodManage = 'sales.payment-method.manage';
    case QuotationView = 'sales.quotation.view';
    case QuotationManage = 'sales.quotation.manage';
    case QuotationDecide = 'sales.quotation.decide';
    case QuotationConvert = 'sales.quotation.convert';
    case OrderView = 'sales.order.view';
    case OrderManage = 'sales.order.manage';
    case OrderCreate = 'sales.order.create';
    case OrderConfirm = 'sales.order.confirm';
    case OrderRelease = 'sales.order.release';
    case OrderCancel = 'sales.order.cancel';
    case OrderClose = 'sales.order.close';
    case DeliveryNoteView = 'sales.delivery-note.view';
    case InvoiceView = 'sales.invoice.view';
    case InvoiceManage = 'sales.invoice.manage';
    case InvoiceIssue = 'sales.invoice.issue';
    case InvoiceSend = 'sales.invoice.send';
    case InvoiceConfirmReceipt = 'sales.invoice.confirm-receipt';
    case PaymentView = 'sales.payment.view';
    case PaymentRecord = 'sales.payment.record';
    case PaymentReverse = 'sales.payment.reverse';
    case CreditNoteView = 'sales.credit-note.view';
    case CreditNoteManage = 'sales.credit-note.manage';
    case CreditNoteConfirm = 'sales.credit-note.confirm';
    case CreditNoteReverse = 'sales.credit-note.reverse';
    case AuditView = 'sales.audit.view';
    case ReportView = 'sales.report.view';
    case Export = 'sales.export';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
