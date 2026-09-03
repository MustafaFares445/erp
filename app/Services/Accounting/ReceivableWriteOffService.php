<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Data\Accounting\WriteOffData;
use App\Enums\InvoiceStatus;
use App\Enums\WriteOffStatus;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use App\Models\ReceivableWriteOff;
use App\Models\User;
use App\Services\Accounting\Exceptions\NoFiscalPeriodForDate;
use App\Services\Concerns\EnforcesMakerChecker;
use App\Services\Sales\DocumentNumberGenerator;
use App\Support\ProportionalAllocator;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final readonly class ReceivableWriteOffService
{
    use EnforcesMakerChecker;

    public function __construct(
        private DocumentNumberGenerator $numbers,
        private FiscalPeriodService $fiscalPeriods,
        private ProportionalAllocator $allocator,
        private WriteOffPostingService $posting,
    ) {}

    public function record(WriteOffData $data, User $actor): ReceivableWriteOff
    {
        Gate::forUser($actor)->authorize('create', ReceivableWriteOff::class);

        return DB::transaction(function () use ($data, $actor): ReceivableWriteOff {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->whereKey($data->invoiceId)
                ->lockForUpdate()
                ->sole();

            $this->assertRecordable($invoice, $data);

            $period = $this->fiscalPeriods->forDate(CarbonImmutable::today());

            if (! $period instanceof FiscalPeriod) {
                throw NoFiscalPeriodForDate::forDate(CarbonImmutable::today()->toDateString());
            }

            $writeOff = new ReceivableWriteOff([
                'write_off_number' => $this->numbers->next(
                    ReceivableWriteOff::withTrashed(),
                    'write_off_number',
                    'WO-'.CarbonImmutable::today()->format('Y').'-',
                ),
                'status' => WriteOffStatus::Draft,
                'customer_id' => $data->customerId,
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => $data->amountMinor,
                'reason_category' => $data->reasonCategory,
                'reason' => trim($data->reason),
                'fiscal_period_id' => $period->getKey(),
            ]);

            $writeOff->forceFill(['recorded_by' => $actor->getKey()])->save();

            activity()
                ->performedOn($writeOff)
                ->causedBy($actor)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'invoice_id' => $invoice->getKey(),
                    'amount_minor' => $data->amountMinor,
                ])
                ->log('accounting.receivable_write_off.recorded');

            return $writeOff->refresh();
        });
    }

    public function approve(ReceivableWriteOff $writeOff, User $actor): ReceivableWriteOff
    {
        Gate::forUser($actor)->authorize('approve', $writeOff);

        return DB::transaction(function () use ($writeOff, $actor): ReceivableWriteOff {
            /** @var ReceivableWriteOff $locked */
            $locked = ReceivableWriteOff::query()
                ->whereKey($writeOff->getKey())
                ->lockForUpdate()
                ->sole();

            $locked->assertCanTransitionTo(WriteOffStatus::Approved);

            $this->assertDifferentActor(
                is_numeric($locked->recorded_by) ? (int) $locked->recorded_by : null,
                $actor,
                'The user who recorded a receivable write-off cannot approve it.',
            );

            /** @var Invoice $invoice */
            $invoice = Invoice::query()
                ->whereKey($locked->invoice_id)
                ->lockForUpdate()
                ->sole();

            if ((int) $invoice->customer_id !== (int) $locked->customer_id) {
                throw new DomainException('The write-off customer must match the invoice customer.');
            }

            if (! $invoice->isIssued()) {
                throw new DomainException('Only an issued invoice can be written off.');
            }

            $invoice->assertCanTransitionTo(InvoiceStatus::WrittenOff);

            $outstandingMinor = $invoice->outstandingMinor();
            $amountMinor = (int) $locked->amount_minor;

            if ($amountMinor <= 0 || $amountMinor > $outstandingMinor) {
                throw new DomainException('The write-off amount exceeds the invoice outstanding balance.');
            }

            $taxTotalMinor = JournalEntryLine::toMinorUnits($invoice->tax_total);
            $invoiceTotalMinor = JournalEntryLine::toMinorUnits($invoice->total_amount);
            $recognisedTaxMinor = JournalEntryLine::toMinorUnits($invoice->recognised_tax_amount);
            $previousWriteOffTaxMinor = (int) $invoice->writeOffs()
                ->where('status', WriteOffStatus::Approved->value)
                ->whereKeyNot($locked->getKey())
                ->sum('tax_amount_minor');

            $taxAmountMinor = $this->allocator->allocate(
                totalMinor: $taxTotalMinor,
                partMinor: $amountMinor,
                wholeMinor: max(1, $invoiceTotalMinor),
                alreadyAllocatedMinor: $recognisedTaxMinor + $previousWriteOffTaxMinor,
                settlesRemainder: $amountMinor === $outstandingMinor,
            );

            $entry = $this->posting->post($actor, $locked, $invoice, $taxAmountMinor);

            $locked->forceFill([
                'status' => WriteOffStatus::Approved,
                'tax_amount_minor' => $taxAmountMinor,
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'journal_entry_id' => $entry->getKey(),
            ])->save();

            $invoice->forceFill(['status' => InvoiceStatus::WrittenOff])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => WriteOffStatus::Draft->value],
                    'attributes' => ['status' => WriteOffStatus::Approved->value],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'invoice_id' => $invoice->getKey(),
                    'journal_entry_id' => $entry->getKey(),
                    'tax_amount_minor' => $taxAmountMinor,
                ])
                ->log('accounting.receivable_write_off.approved');

            return $locked->refresh()->load(['invoice', 'journalEntry', 'fiscalPeriod']);
        });
    }

    public function cancel(ReceivableWriteOff $writeOff, User $actor, string $reason): ReceivableWriteOff
    {
        Gate::forUser($actor)->authorize('cancel', $writeOff);

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A cancellation reason is required.');
        }

        return DB::transaction(function () use ($writeOff, $actor, $reason): ReceivableWriteOff {
            /** @var ReceivableWriteOff $locked */
            $locked = ReceivableWriteOff::query()
                ->whereKey($writeOff->getKey())
                ->lockForUpdate()
                ->sole();

            $locked->assertCanTransitionTo(WriteOffStatus::Cancelled);
            $locked->forceFill(['status' => WriteOffStatus::Cancelled])->save();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges([
                    'old' => ['status' => WriteOffStatus::Draft->value],
                    'attributes' => ['status' => WriteOffStatus::Cancelled->value],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'ip_address' => request()->ip(),
                    'reason' => $reason,
                ])
                ->log('accounting.receivable_write_off.cancelled');

            return $locked->refresh();
        });
    }

    private function assertRecordable(Invoice $invoice, WriteOffData $data): void
    {
        if (! $invoice->isIssued()) {
            throw new DomainException('Only an issued invoice can be written off.');
        }

        if (in_array($invoice->status, [InvoiceStatus::Cancelled, InvoiceStatus::WrittenOff], true)) {
            throw new DomainException('A cancelled or already-written-off invoice cannot be written off.');
        }

        if ((int) $invoice->customer_id !== $data->customerId) {
            throw new DomainException('The write-off customer must match the invoice customer.');
        }

        if ($data->amountMinor <= 0 || $data->amountMinor > $invoice->outstandingMinor()) {
            throw new DomainException('The write-off amount must be positive and no greater than the invoice outstanding balance.');
        }

        if (trim($data->reason) === '') {
            throw new DomainException('A write-off reason is required.');
        }
    }
}
