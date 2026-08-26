<?php

declare(strict_types=1);

namespace App\Enums;

use App\Policies\Concerns\ChecksSalesPermissions;
use Database\Seeders\SalesPermissionSeeder;

/**
 * Canonical `sales.*` permission catalogue (guard: `web`).
 *
 * Single source of truth consumed by {@see SalesPermissionSeeder} and by
 * {@see ChecksSalesPermissions}. Deliberately has no `fixedRoleNames()`
 * method of its own — only {@see DashboardRole::fixedRoleNames()} is ever
 * consulted for the cross-module admin-bypass check.
 *
 * Six separations in this catalogue are load-bearing (FR-072), not
 * granularity for its own sake:
 *
 * - {@see self::QuotationManage} does not imply {@see self::QuotationDecide}
 *   — drafting an offer is our own record; asserting the customer accepted
 *   it commits the company to a price on a third party's behalf.
 * - {@see self::QuotationDecide} does not imply {@see self::QuotationConvert}
 *   — the answer and the commitment to fulfil it are separate acts.
 * - {@see self::InvoiceManage} does not imply {@see self::InvoiceIssue} —
 *   issuing freezes the document, makes it undeletable, and posts to the
 *   ledger. It is the least reversible action in the module.
 * - {@see self::InvoiceIssue} does not imply {@see self::InvoiceSend} —
 *   sending puts the document in front of the customer; a held invoice is
 *   legitimate.
 * - {@see self::PaymentRecord} does not imply {@see self::PaymentReverse} —
 *   recording adds to history, reversal changes the meaning of history
 *   already reported, including tax already recognised.
 * - {@see self::CreditNoteManage} implies neither
 *   {@see self::CreditNoteConfirm} nor {@see self::CreditNoteReverse} — a
 *   credit note is the sole correction path for an issued invoice.
 *
 * `sales.delivery-note.view` grants only the read surface. Completing,
 * dispatching, or cancelling a delivery still requires the existing
 * {@see InventoryPermission} cases, unchanged — a Sales role never gains a
 * second authorization path to a stock mutation (FR-034).
 *
 * @see /specs/019-sales-lifecycle-payments-credits/contracts/permissions.md
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
    case SupplierConfirmationRequest = 'sales.supplier-confirmation.request';
    case OrderView = 'sales.order.view';
    case OrderManage = 'sales.order.manage';
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

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
