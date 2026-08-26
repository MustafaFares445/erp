<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\ChartAccount;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Services\Sales\Exceptions\PostingAccountUnavailable;

/**
 * Resolves and guards the five accounts {@see SalesSetting} names for posting
 * (FR-005, FR-007, contracts/posting.md §0).
 *
 * Resolution happens at posting time, inside the posting transaction, so an
 * account made non-postable or inactive between page load and submit still
 * fails the posting rather than corrupting the ledger.
 */
final readonly class SalesAccountResolver
{
    public function receivable(SalesSetting $settings): ChartAccount
    {
        return $this->resolve($settings->receivableAccount, 'receivable');
    }

    public function revenue(SalesSetting $settings): ChartAccount
    {
        return $this->resolve($settings->revenueAccount, 'revenue');
    }

    public function deferredTax(SalesSetting $settings): ChartAccount
    {
        return $this->resolve($settings->deferredTaxAccount, 'deferred_tax');
    }

    public function taxPayable(SalesSetting $settings): ChartAccount
    {
        return $this->resolve($settings->taxPayableAccount, 'tax_payable');
    }

    public function customerDeposits(SalesSetting $settings): ChartAccount
    {
        return $this->resolve($settings->customerDepositsAccount, 'customer_deposits');
    }

    /**
     * Named separately from the four sales-settings accessors above because a
     * payment method's collection account lives on {@see PaymentMethod},
     * not on {@see SalesSetting}.
     */
    public function collectionFor(ChartAccount $configured): ChartAccount
    {
        return $this->resolve($configured, 'collection');
    }

    private function resolve(?ChartAccount $account, string $role): ChartAccount
    {
        if (! $account instanceof ChartAccount) {
            throw PostingAccountUnavailable::missing($role);
        }

        if (! $account->is_postable) {
            throw PostingAccountUnavailable::notPostable($role, (string) $account->code);
        }

        if (! $account->is_active) {
            throw PostingAccountUnavailable::inactive($role, (string) $account->code);
        }

        return $account;
    }
}
