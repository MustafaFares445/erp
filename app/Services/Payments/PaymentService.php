<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class PaymentService
{
    public function __construct(
        private PaymentAllocationService $allocations,
        private PaymentPostingService $posting,
        private TaxRecognitionService $taxRecognition,
        private JournalPostingService $journalPosting,
    ) {}

    /**
     * @param list<array{invoice_id:int,amount:float|int|string}> $requestedAllocations
     */
    public function post(User $actor, Payment $payment, array $requestedAllocations): Payment
    {
        Gate::forUser($actor)->authorize('post', $payment);

        return DB::transaction(function () use ($actor, $payment, $requestedAllocations): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->with(['paymentMethod.chartAccount'])
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->sole();

            if ($locked->isPosted()) {
                throw new DomainException('This payment has already been posted.');
            }

            $method = $locked->paymentMethod;
            if (! $method instanceof PaymentMethod || ! $method->is_active) {
                throw new DomainException('A posted payment requires an active payment method.');
            }

            if ($method->requires_proof && $locked->getMedia('payment-proof')->isEmpty()) {
                throw new DomainException('This payment method requires payment proof before posting.');
            }

            usort($requestedAllocations, static fn (array $a, array $b): int => $a['invoice_id'] <=> $b['invoice_id']);

            $allocated = 0.0;
            $created = [];

            foreach ($requestedAllocations as $row) {
                $amount = round((float) $row['amount'], 2);
                $allocated += $amount;

                if ($allocated - (float) $locked->amount > 0.00001) {
                    throw new DomainException('Payment allocations cannot exceed the payment amount.');
                }

                $created[] = $this->allocations->allocate($locked, (int) $row['invoice_id'], $amount);
            }

            $this->posting->post($actor, $locked, round($allocated, 2));

            foreach ($created as $allocation) {
                $this->taxRecognition->recognise($actor, $locked, $allocation);
            }

            $locked->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => 'posted']])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.payment.posted');

            return $locked->refresh()->load(['allocations.invoice', 'taxRecognitionEntries']);
        }, attempts: 5);
    }

    public function reverse(User $actor, Payment $payment): Payment
    {
        Gate::forUser($actor)->authorize('reverse', $payment);

        return DB::transaction(function () use ($actor, $payment): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()
                ->with(['allocations', 'journalEntries'])
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->sole();

            if (! $locked->isPosted() || $locked->isReversed()) {
                throw new DomainException('Only an unreversed posted payment can be reversed.');
            }

            foreach ($locked->journalEntries as $entry) {
                if ($entry->isPosted()) {
                    $this->journalPosting->reverse(
                        $actor,
                        $entry,
                        CarbonImmutable::today(),
                        "Reverse payment {$locked->payment_number}",
                    );
                }
            }

            $this->taxRecognition->reverseForPayment($actor, $locked);

            foreach ($locked->allocations()->orderBy('invoice_id')->get() as $allocation) {
                $this->allocations->restore($allocation);
            }

            $locked->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => 'reversed']])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.payment.reversed');

            return $locked->refresh();
        }, attempts: 5);
    }
}
