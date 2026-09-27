<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Invoice;

/**
 * The financial (payment) axis of an {@see Invoice}, independent
 * of its document lifecycle {@see InvoiceStatus} — separating "what state is
 * this document in" from "how much of it is collected" per the Invoice UI
 * redesign (see the Sales Invoices List/View pages).
 */
enum InvoiceFinancialStatus: string
{
    case NotPayableYet = 'not_payable_yet';
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Credited = 'credited';
    case Overdue = 'overdue';

    public function label(): string
    {
        return __('admin.sales.invoice_financial_status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::NotPayableYet => 'gray',
            self::Unpaid => 'danger',
            self::PartiallyPaid => 'warning',
            self::Paid, self::Credited => 'success',
            self::Overdue => 'danger',
        };
    }
}
