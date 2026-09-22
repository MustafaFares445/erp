<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentMethodType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a verified Succeeded {@see PaymentTransaction} into a posted ERP
 * {@see Payment}, exactly once. Uses the narrowly-permissioned
 * {@see SystemActorResolver} actor — never `auth()`, never a real admin —
 * and the existing {@see PaymentService}/{@see PaymentAllocationService} so
 * the settlement follows the exact same rules a dashboard-posted payment
 * does: allocate immediately when the purpose is an issued Invoice,
 * otherwise leave the whole amount as an unallocated Customer Deposit
 * ({@see PaymentPostingService} already credits Customer Deposits for any
 * unallocated remainder).
 */
final readonly class ProviderPaymentSettlementService
{
    public function __construct(
        private SystemActorResolver $systemActor,
        private PaymentService $paymentService,
    ) {}

    public function settle(PaymentTransaction $transaction): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction): PaymentTransaction {
            /** @var PaymentTransaction $locked */
            $locked = PaymentTransaction::query()->whereKey($transaction->getKey())->lockForUpdate()->sole();

            if ($locked->isSettled()) {
                return $locked;
            }

            if (! $locked->status->isSettleable()) {
                throw new DomainException('Only a Succeeded provider transaction can be settled.');
            }

            $actor = $this->systemActor->resolve();
            $method = $this->resolveStripeMethod();
            $purpose = $locked->purpose;

            $payment = $this->paymentService->createDraft($actor, [
                'customer_id' => $locked->customer_id,
                'payment_method_id' => $method->getKey(),
                'amount' => $locked->amount(),
                'currency' => $locked->currency,
                'payment_date' => now()->toDateString(),
                'external_reference' => $locked->payment_intent_id,
            ]);

            /** @var list<array{invoice_id: int, amount: float}> $allocations */
            $allocations = [];

            if ($purpose instanceof Invoice) {
                $toAllocate = round(min($purpose->outstandingAmount(), (float) $payment->amount), 2);
                $invoiceId = $purpose->getKey();

                if ($toAllocate > 0.0 && is_numeric($invoiceId)) {
                    $allocations[] = ['invoice_id' => (int) $invoiceId, 'amount' => $toAllocate];
                }
            }

            $posted = $this->paymentService->post($actor, $payment, $allocations);

            $locked->forceFill(['payment_id' => $posted->getKey()])->save();

            activity()
                ->performedOn($locked)
                ->withProperties(['source_channel' => 'stripe'])
                ->log('payments.provider_transaction.settled');

            return $locked->refresh();
        });
    }

    private function resolveStripeMethod(): PaymentMethod
    {
        $method = PaymentMethod::query()
            ->where('type', PaymentMethodType::Stripe->value)
            ->where('is_active', true)
            ->first();

        if (! $method instanceof PaymentMethod) {
            throw new DomainException('No active Stripe payment method is configured.');
        }

        return $method;
    }
}
