<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\SalesAccountResolver;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class PaymentPostingService
{
    public function __construct(
        private SalesAccountResolver $accounts,
        private JournalPostingService $journalPosting,
    ) {}

    public function post(User $actor, Payment $payment, float $allocatedAmount): JournalEntry
    {
        $settings = SalesSetting::current()->load(['receivableAccount', 'customerDepositsAccount']);
        $method = $payment->paymentMethod;

        if (! $method instanceof PaymentMethod || ! $method->is_active || ! $method->chartAccount) {
            throw new DomainException('A posted payment requires an active payment method with a collection account.');
        }

        $collection = $this->accounts->collectionFor($method->chartAccount);
        $receivable = $this->accounts->receivable($settings);
        $remainder = round((float) $payment->amount - $allocatedAmount, 2);

        $lines = [
            [
                'chart_account_id' => (int) $collection->getKey(),
                'debit' => (string) $payment->amount,
                'credit' => '0.00',
                'description' => "Collection {$payment->payment_number}",
            ],
        ];

        if ($allocatedAmount > 0.0) {
            $lines[] = [
                'chart_account_id' => (int) $receivable->getKey(),
                'debit' => '0.00',
                'credit' => number_format($allocatedAmount, 2, '.', ''),
                'description' => 'Accounts receivable settlement',
            ];
        }

        if ($remainder > 0.0) {
            $deposits = $this->accounts->customerDeposits($settings);
            $lines[] = [
                'chart_account_id' => (int) $deposits->getKey(),
                'debit' => '0.00',
                'credit' => number_format($remainder, 2, '.', ''),
                'description' => 'Unallocated customer deposit',
            ];
        }

        if (count($lines) < 2) {
            throw new DomainException('A payment must either settle a receivable or create a customer deposit.');
        }

        return $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($payment->payment_date),
            $lines,
            "Customer payment {$payment->payment_number}",
            $payment,
        );
    }
}
