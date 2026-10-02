<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyLineCategory;
use App\Enums\WarrantyStatus;
use App\Events\MaintenanceRecordBilled;
use App\Models\Invoice;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\Payment;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Payments\CustomerDepositApplicationService;
use App\Services\Payments\SystemActorResolver;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\LineTotalCalculator;
use App\Services\Sales\QuotationService;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class MaintenanceBillingService
{
    public function __construct(
        private QuotationService $quotationService,
        private InvoiceService $invoiceService,
        private PriceResolver $priceResolver,
        private LineTotalCalculator $calculator,
        private CustomerDepositApplicationService $depositApplication,
        private SystemActorResolver $systemActor,
    ) {}

    public function markWarrantyCovered(MaintenanceRecord $record, User $user, string $reason): MaintenanceRecord
    {
        Gate::forUser($user)->authorize('bill', $record);

        if (mb_trim($reason) === '') {
            throw InvalidBillingTransition::reasonRequired();
        }

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $locked = $this->lockedRecord($record);

            if ($locked->coverage_decision === WarrantyClaimDecision::PendingDiagnosis) {
                $locked->update([
                    'coverage_decision' => $locked->warranty_status === WarrantyStatus::Covered
                        ? WarrantyClaimDecision::FullyCovered
                        : WarrantyClaimDecision::ThirdPartyWarranty,
                    'coverage_source' => $locked->warranty_status === WarrantyStatus::Covered
                        ? WarrantyCoverageSource::SellerWarranty
                        : WarrantyCoverageSource::ManufacturerWarranty,
                    'coverage_reason' => $reason,
                    'customer_coverage_explanation' => $reason,
                    'coverage_decided_at' => now(),
                    'coverage_decided_by' => $user->getKey(),
                ]);
                $locked->refresh();
            }

            $this->assertBillable($locked);

            $locked->update([
                'billing_type' => MaintenanceBillingType::WarrantyCovered,
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withChanges([
                    'old' => ['billing_type' => MaintenanceBillingType::Unbilled->value],
                    'attributes' => [
                        'billing_type' => MaintenanceBillingType::WarrantyCovered->value,
                        'reason' => $reason,
                    ],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => $reason,
                    'legacy_billing_path' => true,
                    'coverage_decision' => $locked->coverage_decision->value,
                    'coverage_source' => $locked->coverage_source?->value,
                ])
                ->log('support.maintenance_record.warranty_covered');

            return $record->refresh();
        });
    }

    public function settleCoverage(MaintenanceRecord $record, User $user, string $reason): MaintenanceRecord
    {
        Gate::forUser($user)->authorize('bill', $record);

        if (mb_trim($reason) === '') {
            throw InvalidBillingTransition::reasonRequired();
        }

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $locked = $this->lockedRecord($record);

            $billingType = match ($locked->coverage_decision) {
                WarrantyClaimDecision::FullyCovered => MaintenanceBillingType::WarrantyCovered,
                WarrantyClaimDecision::Goodwill => MaintenanceBillingType::GoodwillCovered,
                WarrantyClaimDecision::ThirdPartyWarranty => MaintenanceBillingType::ThirdPartyCovered,
                WarrantyClaimDecision::ServiceContract => MaintenanceBillingType::ServiceContractCovered,
                default => throw ValidationException::withMessages([
                    'coverage_decision' => 'This coverage decision still requires customer billing or approval.',
                ]),
            };

            if (
                $locked->coverage_decision === WarrantyClaimDecision::FullyCovered
                && $locked->coverage_source === WarrantyCoverageSource::SellerWarranty
                && $locked->warranty_status !== WarrantyStatus::Covered
            ) {
                throw ValidationException::withMessages([
                    'coverage_decision' => 'Seller-warranty settlement requires an active warranty entitlement.',
                ]);
            }

            $this->assertBillable($locked);

            $locked->update([
                'billing_type' => $billingType,
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withChanges([
                    'old' => ['billing_type' => MaintenanceBillingType::Unbilled->value],
                    'attributes' => ['billing_type' => $billingType->value, 'reason' => $reason],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => $reason,
                    'coverage_decision' => $locked->coverage_decision->value,
                    'coverage_source' => $locked->coverage_source?->value,
                ])
                ->log('support.maintenance_record.coverage_settled');

            return $record->refresh();
        });
    }

    /**
     * Marks a closed maintenance request as already recovered by its source
     * ticket's settled support payment. This closes the previously missing
     * path represented by MaintenanceBillingType::TicketSettled.
     */
    public function markTicketSettled(MaintenanceRecord $record, User $user, string $reason): MaintenanceRecord
    {
        Gate::forUser($user)->authorize('bill', $record);

        if (mb_trim($reason) === '') {
            throw InvalidBillingTransition::reasonRequired();
        }

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $locked = $this->lockedRecord($record);

            $this->assertBillable($locked);
            $locked->loadMissing('ticket.paymentLink.payment');

            $link = $locked->ticket?->paymentLink;

            if (! $link instanceof TicketPaymentLink || $link->status !== PaymentLinkStatus::Settled) {
                throw ValidationException::withMessages([
                    'record' => 'The linked ticket does not have a settled support payment.',
                ]);
            }

            $payment = $link->payment;

            if (! $payment instanceof Payment || ! $payment->isPosted()) {
                throw ValidationException::withMessages([
                    'record' => 'The settled ticket payment has not been posted to accounting.',
                ]);
            }

            $financialActor = $this->systemActor->resolve();
            $invoice = $this->invoiceService->createStandalone($financialActor, [
                'customer_id' => $locked->customer_id,
                'description' => sprintf('Support fee for ticket %s', $locked->ticket->ticket_number),
            ], [$this->ticketFeeInvoiceLine($link)]);
            $invoice->forceFill(['maintenance_record_id' => $locked->id])->save();

            $issued = $this->invoiceService->issue($financialActor, $invoice);
            $this->depositApplication->applyEligibleDeposits($issued);
            $issued->refresh();

            if ($issued->outstandingAmount() > 0.009) {
                throw ValidationException::withMessages([
                    'record' => 'The settled support payment could not be fully applied to its service invoice.',
                ]);
            }

            $locked->update([
                'billing_type' => MaintenanceBillingType::TicketSettled,
                'invoice_id' => $issued->getKey(),
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withChanges([
                    'old' => ['billing_type' => MaintenanceBillingType::Unbilled->value],
                    'attributes' => ['billing_type' => MaintenanceBillingType::TicketSettled->value, 'reason' => $reason],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => $reason,
                    'ticket_id' => $locked->ticket_id,
                    'ticket_payment_link_id' => $link->getKey(),
                    'payment_id' => $payment->getKey(),
                    'invoice_id' => $issued->getKey(),
                ])
                ->log('support.maintenance_record.ticket_settled');

            return $record->refresh();
        });
    }

    public function reclassifyWarrantyForBilling(MaintenanceRecord $record, User $user, string $reason): MaintenanceRecord
    {
        Gate::forUser($user)->authorize('bill', $record);

        if (mb_trim($reason) === '') {
            throw InvalidBillingTransition::reasonRequired();
        }

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $locked = $this->lockedRecord($record);

            if ($locked->billing_type !== MaintenanceBillingType::WarrantyCovered) {
                throw InvalidBillingTransition::alreadyBilled($locked->billing_type->value);
            }

            $locked->update([
                'billing_type' => MaintenanceBillingType::Unbilled,
                'billed_at' => null,
                'coverage_decision' => WarrantyClaimDecision::Rejected,
                'coverage_source' => WarrantyCoverageSource::CustomerPaid,
                'coverage_reason' => $reason,
                'customer_coverage_explanation' => $reason,
                'coverage_decided_at' => now(),
                'coverage_decided_by' => $user->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withChanges([
                    'old' => ['billing_type' => MaintenanceBillingType::WarrantyCovered->value],
                    'attributes' => ['billing_type' => MaintenanceBillingType::Unbilled->value, 'reason' => $reason],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'reason' => $reason])
                ->log('support.maintenance_record.warranty_reclassified');

            return $record->refresh();
        });
    }

    public function createQuotation(MaintenanceRecord $record, User $user): Quotation
    {
        Gate::forUser($user)->authorize('bill', $record);

        return DB::transaction(function () use ($record, $user): Quotation {
            $locked = $this->lockedRecord($record);
            $this->assertQuotable($locked);

            $locked->loadMissing('quotation');
            $previousQuotation = $locked->quotation;
            $lines = $previousQuotation instanceof Quotation
                ? $this->actualCustomerResponsibilityLines($locked)
                : $this->customerResponsibilityLines($locked);

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'record' => 'There is no customer responsibility to quote for this coverage decision.',
                ]);
            }

            if ($previousQuotation instanceof Quotation && $previousQuotation->status === QuotationStatus::Accepted) {
                $currentAmount = $this->linesTotal($lines);
                $approvedAmount = round((float) $previousQuotation->grand_total, 2);

                if ($currentAmount <= $approvedAmount + 0.009) {
                    throw ValidationException::withMessages([
                        'record' => sprintf(
                            'The accepted quotation already covers the current customer responsibility (approved %.2f, current %.2f). Create the final invoice instead.',
                            $approvedAmount,
                            $currentAmount,
                        ),
                    ]);
                }
            }

            $quotation = $this->quotationService->create(
                [
                    'customer_id' => $locked->customer_id,
                    'issue_date' => now()->toDateString(),
                    'notes' => sprintf(
                        'Customer responsibility for maintenance request #%d after coverage assessment.',
                        $locked->id,
                    ),
                ],
                $lines,
            );

            if ($previousQuotation instanceof Quotation) {
                $quotation->forceFill(['requoted_from_id' => $previousQuotation->getKey()])->save();
            }

            $locked->update([
                'billing_type' => MaintenanceBillingType::Quoted,
                'quotation_id' => $quotation->getKey(),
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'quotation_id' => $quotation->getKey(),
                    'requoted_from_id' => $previousQuotation?->getKey(),
                    'customer_responsibility_minor' => (int) $locked->coverageLines()->sum('customer_amount_minor'),
                ])
                ->log('support.maintenance_record.quoted');

            $record->refresh();

            return $quotation;
        });
    }

    public function createInvoice(MaintenanceRecord $record, User $user): Invoice
    {
        Gate::forUser($user)->authorize('bill', $record);

        return DB::transaction(function () use ($record, $user): Invoice {
            $locked = $this->lockedRecord($record);
            $this->assertInvoiceable($locked);

            $hasCoverageAssessment = $locked->coverageLines()->exists()
                || in_array($locked->coverage_decision, [
                    WarrantyClaimDecision::PartiallyCovered,
                    WarrantyClaimDecision::Rejected,
                    WarrantyClaimDecision::FullyCovered,
                    WarrantyClaimDecision::Goodwill,
                    WarrantyClaimDecision::ThirdPartyWarranty,
                    WarrantyClaimDecision::ServiceContract,
                ], true);

            $lines = $hasCoverageAssessment
                ? $this->actualCustomerResponsibilityLines($locked)
                : [...$this->partsLines($locked), ...$this->labourLine($locked), ...$this->thirdPartyLines($locked)];

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'record' => 'This maintenance request has no priceable parts or labour to bill.',
                ]);
            }

            if ($locked->billing_type === MaintenanceBillingType::Quoted) {
                $locked->loadMissing('quotation');
                $currentQuotation = $locked->quotation;

                if (! $currentQuotation instanceof Quotation) {
                    throw ValidationException::withMessages([
                        'record' => 'The customer-responsibility quotation is missing.',
                    ]);
                }

                if (in_array($currentQuotation->status, [
                    QuotationStatus::Draft,
                    QuotationStatus::Sent,
                    QuotationStatus::ChangesRequested,
                ], true)) {
                    throw ValidationException::withMessages([
                        'record' => 'The latest customer quotation must be decided before creating the final invoice.',
                    ]);
                }

                $acceptedQuotation = $this->latestAcceptedQuotation($currentQuotation);

                if (! $acceptedQuotation instanceof Quotation) {
                    throw ValidationException::withMessages([
                        'record' => 'The customer-responsibility quotation must be accepted before creating the final invoice.',
                    ]);
                }

                $invoiceAmount = $this->linesTotal($lines);
                $approvedAmount = round((float) $acceptedQuotation->grand_total, 2);

                if ($invoiceAmount > $approvedAmount + 0.009) {
                    throw ValidationException::withMessages([
                        'record' => sprintf(
                            'The final invoice amount %.2f exceeds the customer-approved quotation %.2f. Create and obtain acceptance for a revised quotation first.',
                            $invoiceAmount,
                            $approvedAmount,
                        ),
                    ]);
                }
            }

            $financialActor = $this->systemActor->resolve();
            $invoice = $this->invoiceService->createStandalone($financialActor, [
                'customer_id' => $locked->customer_id,
                'description' => sprintf('Service job #%d', $locked->id),
            ], $lines);

            $invoice->forceFill(['maintenance_record_id' => $locked->id])->save();

            $locked->update([
                'billing_type' => MaintenanceBillingType::Invoiced,
                'invoice_id' => $invoice->getKey(),
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties(['source_channel' => 'dashboard', 'invoice_id' => $invoice->getKey()])
                ->log('support.maintenance_record.invoiced');

            DB::afterCommit(static fn () => MaintenanceRecordBilled::dispatch($locked->refresh()));

            $record->refresh();

            return $invoice->refresh();
        });
    }

    /**
     * Re-reads the record under a row lock so every billing guard runs against
     * the committed state — a stale in-memory model (double click, second
     * tab) must never pass a guard its fresh copy would fail.
     */
    private function lockedRecord(MaintenanceRecord $record): MaintenanceRecord
    {
        return MaintenanceRecord::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertBillable(MaintenanceRecord $record): void
    {
        if ($record->status !== MaintenanceStatus::Closed) {
            throw InvalidBillingTransition::notClosed();
        }

        if ($record->billing_type === MaintenanceBillingType::WarrantyCovered) {
            throw InvalidBillingTransition::warrantyMustBeReclassified();
        }

        if ($record->billing_type->isSettled()) {
            throw InvalidBillingTransition::alreadyBilled($record->billing_type->value);
        }
    }

    private function assertInvoiceable(MaintenanceRecord $record): void
    {
        if ($record->status !== MaintenanceStatus::Closed) {
            throw InvalidBillingTransition::notClosed();
        }

        if ($record->billing_type === MaintenanceBillingType::Quoted) {
            return;
        }

        $this->assertBillable($record);
    }

    private function assertQuotable(MaintenanceRecord $record): void
    {
        if (! in_array($record->status, [MaintenanceStatus::AwaitingApproval, MaintenanceStatus::Closed], true)) {
            throw ValidationException::withMessages([
                'record' => 'A repair quotation can be created after coverage assessment while awaiting customer approval, or after the job is closed.',
            ]);
        }

        if ($record->billing_type->isSettled()) {
            throw InvalidBillingTransition::alreadyBilled($record->billing_type->value);
        }

        if ($record->billing_type === MaintenanceBillingType::Quoted) {
            $record->loadMissing('quotation');

            if (! in_array($record->quotation?->status, [
                QuotationStatus::Accepted,
                QuotationStatus::Rejected,
                QuotationStatus::Expired,
                QuotationStatus::Cancelled,
            ], true)) {
                throw ValidationException::withMessages([
                    'record' => 'A customer-responsibility quotation already exists for this maintenance request.',
                ]);
            }
        }

        if (in_array($record->coverage_decision, [
            WarrantyClaimDecision::FullyCovered,
            WarrantyClaimDecision::Goodwill,
            WarrantyClaimDecision::ThirdPartyWarranty,
            WarrantyClaimDecision::ServiceContract,
        ], true)) {
            throw ValidationException::withMessages([
                'record' => 'This coverage decision leaves no customer responsibility to quote.',
            ]);
        }
    }

    /** @return array{description:string,quantity:int,unit_price:float,tax_amount:float} */
    private function ticketFeeInvoiceLine(TicketPaymentLink $link): array
    {
        $gross = round((float) $link->amount, 2);
        $taxPercent = max(0.0, (float) SalesSetting::current()->default_tax_percent);
        $net = $taxPercent > 0.0
            ? round($gross / (1 + ($taxPercent / 100)), 2)
            : $gross;
        $tax = round($gross - $net, 2);

        return [
            'description' => sprintf('Support ticket fee #%d', $link->ticket_id),
            'quantity' => 1,
            'unit_price' => $net,
            'tax_amount' => $tax,
        ];
    }

    /**
     * @param  list<array{description?:string,product_variant_id?:int,quantity:int|float,unit_price:float,tax_amount:float}>  $lines
     */
    private function linesTotal(array $lines): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            $unitPrice = (float) $line['unit_price'];
            $tax = (float) $line['tax_amount'];

            $total += round($quantity * $unitPrice, 2) + round($tax, 2);
        }

        return round($total, 2);
    }

    private function latestAcceptedQuotation(Quotation $quotation): ?Quotation
    {
        $current = $quotation;
        $visited = [];

        while (true) {
            $key = $current->getKey();
            if (! is_int($key) || isset($visited[$key])) {
                return null;
            }

            $visited[$key] = true;

            if ($current->status === QuotationStatus::Accepted) {
                return $current;
            }

            $parent = $current->requotedFrom()->first();
            if (! $parent instanceof Quotation) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * Builds the final customer charge from the actual repair work while
     * preserving the coverage percentages that were approved during diagnosis.
     * New work that did not exist in the frozen coverage assessment is treated
     * as customer responsibility until a revised quotation is accepted.
     *
     * @return list<array{description:string, quantity:int, unit_price:float, tax_amount:float}|array{product_variant_id:int, quantity:float, unit_price:float, tax_amount:float}>
     */
    private function actualCustomerResponsibilityLines(MaintenanceRecord $record): array
    {
        $coverageLines = $record->coverageLines()->orderBy('id')->get();

        if ($coverageLines->isEmpty()) {
            if (in_array($record->coverage_decision, [
                WarrantyClaimDecision::FullyCovered,
                WarrantyClaimDecision::Goodwill,
                WarrantyClaimDecision::ThirdPartyWarranty,
                WarrantyClaimDecision::ServiceContract,
            ], true)) {
                return [];
            }

            return [...$this->partsLines($record), ...$this->labourLine($record), ...$this->thirdPartyLines($record)];
        }

        $record->loadMissing([
            'serviceRecords.parts.productVariant',
            'customer.user',
            'labourEntries',
            'thirdPartyCosts',
        ]);

        $taxPercent = (float) SalesSetting::current()->default_tax_percent;
        $defaultUnassessedCustomerPercent = in_array($record->coverage_decision, [
            WarrantyClaimDecision::PartiallyCovered,
            WarrantyClaimDecision::Rejected,
        ], true) ? 100.0 : 0.0;
        $usedCoverageLineIds = [];
        $lines = [];

        foreach ($record->serviceRecords as $task) {
            foreach ($task->parts as $part) {
                if ($part->reversed_at !== null) {
                    continue;
                }
                /** @var ProductVariant $productVariant */
                $productVariant = $part->productVariant;
                $coverageLine = $coverageLines->first(
                    fn (MaintenanceCoverageLine $line): bool => $line->source_type === ServiceRecordPart::class
                        && (int) $line->source_id === (int) $part->id,
                );
                $customerPercent = $coverageLine !== null
                    ? max(0.0, 100.0 - (float) $coverageLine->coverage_percent)
                    : $defaultUnassessedCustomerPercent;

                if ($coverageLine !== null && is_int($coverageLine->getKey())) {
                    $usedCoverageLineIds[$coverageLine->getKey()] = true;
                }

                if ($customerPercent <= 0.0) {
                    continue;
                }

                $quantity = (float) $part->quantity;
                $fullUnitPrice = round($this->priceResolver->resolve(
                    $productVariant,
                    $record->customer?->user,
                )->amount, 2);
                $unitPrice = round($fullUnitPrice * ($customerPercent / 100), 2);

                $lines[] = [
                    'product_variant_id' => $part->product_variant_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_amount' => $this->calculator->defaultTax($quantity, $unitPrice, $taxPercent),
                ];
            }
        }

        $labourSum = $record->labourEntries->sum('total_cost_minor');
        $labourMinor = is_numeric($labourSum) ? (int) $labourSum : 0;
        if ($labourMinor > 0) {
            $labourCoverage = $coverageLines->first(
                fn (MaintenanceCoverageLine $line): bool => $line->source_type === 'maintenance_labour'
                    || ($line->source_type === null && $line->category === WarrantyLineCategory::Labour),
            );
            $customerPercent = $labourCoverage !== null
                ? max(0.0, 100.0 - (float) $labourCoverage->coverage_percent)
                : $defaultUnassessedCustomerPercent;

            if ($labourCoverage !== null && is_int($labourCoverage->getKey())) {
                $usedCoverageLineIds[$labourCoverage->getKey()] = true;
            }

            if ($customerPercent > 0.0) {
                $unitPrice = round(($labourMinor / 100) * ($customerPercent / 100), 2);
                $lines[] = [
                    'description' => 'Labour',
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'tax_amount' => $this->calculator->defaultTax(1, $unitPrice, $taxPercent),
                ];
            }
        }

        foreach ($record->thirdPartyCosts as $cost) {
            $coverageLine = $coverageLines->first(
                fn (MaintenanceCoverageLine $line): bool => $line->source_type === MaintenanceThirdPartyCost::class
                    && (int) $line->source_id === (int) $cost->id,
            );
            $customerPercent = $coverageLine !== null
                ? max(0.0, 100.0 - (float) $coverageLine->coverage_percent)
                : $defaultUnassessedCustomerPercent;

            if ($coverageLine !== null && is_int($coverageLine->getKey())) {
                $usedCoverageLineIds[$coverageLine->getKey()] = true;
            }

            if ($customerPercent <= 0.0) {
                continue;
            }

            $unitPrice = round(($cost->amount_minor / 100) * ($customerPercent / 100), 2);
            $lines[] = [
                'description' => (string) $cost->description,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_amount' => $this->calculator->defaultTax(1, $unitPrice, $taxPercent),
            ];
        }

        foreach ($coverageLines as $coverageLine) {
            $key = $coverageLine->getKey();
            if (is_int($key) && isset($usedCoverageLineIds[$key])) {
                continue;
            }
            if ($coverageLine->source_type !== null) {
                continue;
            }
            if ((int) $coverageLine->customer_amount_minor <= 0) {
                continue;
            }

            $unitPrice = round(((int) $coverageLine->customer_amount_minor) / 100, 2);
            $lines[] = [
                'description' => (string) $coverageLine->description,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_amount' => $this->calculator->defaultTax(1, $unitPrice, $taxPercent),
            ];
        }

        return $lines;
    }

    /**
     * Converts the frozen post-coverage customer share into Sales service lines
     * for the initial quotation. Final invoicing uses the actual repair work
     * through actualCustomerResponsibilityLines().
     *
     * @return list<array{description:string, quantity:int, unit_price:float, tax_amount:float}|array{product_variant_id:int, quantity:float, unit_price:float, tax_amount:float}>
     */
    private function customerResponsibilityLines(MaintenanceRecord $record): array
    {
        $coverageLines = $record->coverageLines()
            ->where('customer_amount_minor', '>', 0)
            ->orderBy('id')
            ->get();

        if ($coverageLines->isEmpty()) {
            if ($record->coverage_decision === WarrantyClaimDecision::Rejected) {
                return [...$this->partsLines($record), ...$this->labourLine($record), ...$this->thirdPartyLines($record)];
            }

            if (in_array($record->coverage_decision, [
                WarrantyClaimDecision::PartiallyCovered,
                WarrantyClaimDecision::FullyCovered,
                WarrantyClaimDecision::Goodwill,
                WarrantyClaimDecision::ThirdPartyWarranty,
                WarrantyClaimDecision::ServiceContract,
            ], true)) {
                return [];
            }

            return [...$this->partsLines($record), ...$this->labourLine($record)];
        }

        $taxPercent = (float) SalesSetting::current()->default_tax_percent;
        $lines = [];

        foreach ($coverageLines as $line) {
            $unitPrice = round(((int) $line->customer_amount_minor) / 100, 2);
            $lines[] = [
                'description' => (string) $line->description,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_amount' => $this->calculator->defaultTax(1, $unitPrice, $taxPercent),
            ];
        }

        return $lines;
    }

    /** @return list<array{product_variant_id:int, quantity:float, unit_price:float, tax_amount:float}> */
    private function partsLines(MaintenanceRecord $record): array
    {
        $record->loadMissing(['serviceRecords.parts.productVariant', 'customer.user']);

        $customerUser = $record->customer?->user;
        $taxPercent = (float) SalesSetting::current()->default_tax_percent;

        /** @var list<ServiceRecordPart> $parts */
        $parts = $record->serviceRecords
            ->flatMap(fn (MaintenanceTask $task): Collection => $task->parts)
            ->filter(fn (ServiceRecordPart $part): bool => $part->reversed_at === null)
            ->values()
            ->all();

        $lines = [];

        foreach ($parts as $part) {
            $variant = $part->productVariant;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $quantity = (float) $part->quantity;
            $unitPrice = round($this->priceResolver->resolve($variant, $customerUser)->amount, 2);

            $lines[] = [
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_amount' => $this->calculator->defaultTax($quantity, $unitPrice, $taxPercent),
            ];
        }

        return $lines;
    }

    /** @return list<array{description:string, quantity:int, unit_price:float, tax_amount:float}> */
    private function labourLine(MaintenanceRecord $record): array
    {
        $record->loadMissing('labourEntries');

        $sum = $record->labourEntries->sum('total_cost_minor');
        $totalMinor = is_numeric($sum) ? (int) $sum : 0;

        if ($totalMinor <= 0) {
            return [];
        }

        $unitPrice = round($totalMinor / 100, 2);
        $taxPercent = (float) SalesSetting::current()->default_tax_percent;

        return [[
            'description' => 'Labour',
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'tax_amount' => $this->calculator->defaultTax(1, $unitPrice, $taxPercent),
        ]];
    }

    /** @return list<array{description:string, quantity:int, unit_price:float, tax_amount:float}> */
    private function thirdPartyLines(MaintenanceRecord $record): array
    {
        $record->loadMissing('thirdPartyCosts');
        $taxPercent = (float) SalesSetting::current()->default_tax_percent;
        $lines = [];

        foreach ($record->thirdPartyCosts as $cost) {
            $unitPrice = round(((int) $cost->amount_minor) / 100, 2);

            if ($unitPrice <= 0.0) {
                continue;
            }

            $lines[] = [
                'description' => (string) $cost->description,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'tax_amount' => $this->calculator->defaultTax(1, $unitPrice, $taxPercent),
            ];
        }

        return $lines;
    }
}
