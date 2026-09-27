<?php

declare(strict_types=1);

namespace App\Data\Sales;

final readonly class OrderFinancialProjection
{
    public function __construct(
        public float $orderTotal,
        public float $customerDepositCollected,
        public float $issuedInvoiceTotal,
        public float $invoicePaidAmount,
        public float $invoiceCreditedAmount,
        public float $invoiceWrittenOffAmount,
        public float $invoiceOutstandingAmount,
        public int $draftInvoiceCount,
        public int $issuedInvoiceCount,
        public bool $financiallySettled,
    ) {}
}
