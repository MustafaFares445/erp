<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceConfirmationType: string
{
    case CustomerReceived = 'customer_received';
    case EmployeeConfirmedReceived = 'employee_confirmed_received';

    public function label(): string
    {
        return __('admin.sales.invoice_confirmation_type.'.$this->value);
    }
}
