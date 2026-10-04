<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/**
 * Request-local accounts receivable index shared by customer table rows.
 */
final class CustomerReceivablesSnapshot
{
    /** @var array<int, int>|null */
    private ?array $outstandingByCustomer = null;

    public function outstandingMinor(int $customerId): int
    {
        if ($this->outstandingByCustomer === null) {
            $this->outstandingByCustomer = [];

            foreach (app(AccountsReceivableService::class)->aging()['customers'] as $customer) {
                $this->outstandingByCustomer[$customer['customer_id']] = $customer['outstanding_minor'];
            }
        }

        return $this->outstandingByCustomer[$customerId] ?? 0;
    }
}
