<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\DocumentNumberGenerator;
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
        private DocumentNumberGenerator $documentNumbers,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createDraft(User $actor, array $attributes, ?string $proofPath = null): Payment
    {
        Gate::forUser($actor)->authorize('create', Payment::class);

        return DB::transaction(function () use ($actor, $attributes, $proofPath): Payment {
            $methodId = $attributes['payment_method_id'] ?? null;
            $amount = $attributes['amount'] ?? null;

            if (! is_numeric($methodId) || ! is_numeric($amount) || (float) $amount <= 0.0) {
                throw new DomainException('A payment requires an active method and a positive amount.');
            }

            $method = PaymentMethod::query()
                ->whereKey((int) $methodId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $method instanceof PaymentMethod) {
                throw new DomainException('The selected payment method is not active.');
            }

            $payment = new Payment([
                'customer_id' => $attributes['customer_id'] ?? null,
                'payment_method_id' => $method->getKey(),
                'amount' => round((float) $amount, 2),
                'currency' => is_string($attributes['currency'] ?? null) ? $attributes['currency'] : 'USD',
                'source' => 'manual',
                'payment_date' => $attributes['payment_date'] ?? now()->toDateString(),
                'external_reference' => is_string($attributes['external_reference'] ?? null)
                    ? $attributes['external_reference']
                    : null,
                'notes' => is_string($attributes['notes'] ?? null) ? $attributes['notes'] : null,
                'status' => PaymentStatus::Draft,
            ]);

            $payment->forceFill([
                'payment_number' => $this->documentNumbers->next(Payment::withTrashed(), 'payment_number', 'PAY-'),
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            if (is_string($proofPath) && $proofPath !== '') {
                $payment->addMedia($proofPath)->toMediaCollection('payment-proof');
            }

            activity()->performedOn($payment)->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.payment.created');

            return $payment->refresh();
        }, attempts: 5);
    }

    /**
     * @param  list<array{invoice_id:int,amount:float|int|string}>  $requestedAllocations
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

            $locked->assertCanTransitionTo(PaymentStatus::Posted);

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
                'status' => PaymentStatus::Posted,
                'posted_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => PaymentStatus::Posted->value]])
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

            $locked->assertCanTransitionTo(PaymentStatus::Reversed);

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
                'status' => PaymentStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ])->save();

            activity()->performedOn($locked)->causedBy($actor)
                ->withChanges(['attributes' => ['status' => PaymentStatus::Reversed->value]])
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('sales.payment.reversed');

            return $locked->refresh();
        }, attempts: 5);
    }
}
