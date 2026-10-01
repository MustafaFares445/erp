<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\CreditNoteStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Events\PaymentReceived;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\Sales\DocumentNumberGenerator;
use App\Services\Settings\CurrencyCatalogService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class PaymentService
{
    public function __construct(
        private PaymentAllocationService $allocations,
        private PaymentPostingService $posting,
        private TaxRecognitionService $taxRecognition,
        private CustomerDepositApplicationService $depositApplication,
        private JournalPostingService $journalPosting,
        private DocumentNumberGenerator $documentNumbers,
        private CurrencyCatalogService $currencies,
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
                'currency' => $this->currencies->normalizeBase(
                    is_string($attributes['currency'] ?? null) ? $attributes['currency'] : $this->currencies->defaultCode(),
                    'currency',
                ),
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

            $this->currencies->normalizeBase((string) $locked->currency, 'currency');

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

            DB::afterCommit(static fn () => PaymentReceived::dispatch(
                $locked->refresh()->load('customer.user'),
            ));

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

            $this->assertNoLaterCreditOrRefund($locked);

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

            $this->depositApplication->reverseForPayment($actor, $locked);
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

    /**
     * A credit note or refund taken after this payment already moved the cash
     * and tax this payment recognised, so reversing the payment on top of it
     * would undo the same money twice.
     */
    private function assertNoLaterCreditOrRefund(Payment $payment): void
    {
        $postedAt = $payment->posted_at ?? $payment->created_at;
        $invoiceIds = $payment->allocations->pluck('invoice_id')->all();

        $laterCreditNoteExists = $invoiceIds !== [] && CreditNote::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', CreditNoteStatus::Confirmed->value)
            ->whereNull('reversed_at')
            ->where('confirmed_at', '>=', $postedAt)
            ->lockForUpdate()
            ->exists();

        $laterInvoiceRefundExists = $invoiceIds !== [] && Refund::query()
            ->whereIn('status', [RefundStatus::Approved->value, RefundStatus::Paid->value])
            ->where('created_at', '>=', $postedAt)
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('invoice_id', $invoiceIds)
                ->orWhereIn('credit_note_id', CreditNote::query()->whereIn('invoice_id', $invoiceIds)->select('id')))
            ->lockForUpdate()
            ->exists();

        $laterDepositRefundExists = $payment->customerDepositMinor() > 0 && Refund::query()
            ->where('customer_id', $payment->customer_id)
            ->whereIn('status', [RefundStatus::Approved->value, RefundStatus::Paid->value])
            ->where('customer_deposit_amount', '>', 0)
            ->where('created_at', '>=', $postedAt)
            ->lockForUpdate()
            ->exists();

        if ($laterCreditNoteExists || $laterInvoiceRefundExists || $laterDepositRefundExists) {
            throw new DomainException('Reverse the later credit note or refund first.');
        }
    }
}
