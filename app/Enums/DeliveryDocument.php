<?php

declare(strict_types=1);

namespace App\Enums;

enum DeliveryDocument: string
{
    case PaymentReceipt = 'payment_receipt';
    case PackingList = 'packing_list';
    case OriginalInvoice = 'original_invoice';
    case Quotation = 'quotation';
    case CustomsPayment = 'customs_payment';
    case CustomsClearanceDocument = 'customs_clearance_document';
    case ReceiptVoucher = 'receipt_voucher';

    public function label(): string
    {
        return __('admin.inventory.operation.documents.'.$this->value);
    }
}
