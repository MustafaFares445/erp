<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseOrderDocument: string
{
    case CustomsPayment = 'customs_payment';
    case CustomsClearanceDocument = 'customs_clearance_document';

    public function label(): string
    {
        return __('admin.purchasing.documents.'.$this->value);
    }
}
