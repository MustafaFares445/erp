<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyStatus;
use App\Events\MaintenanceRecordBilled;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
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
    ) {}

    public function markWarrantyCovered(MaintenanceRecord $record, User $user, string $reason): MaintenanceRecord
    {
        if ($record->coverage_decision === WarrantyClaimDecision::PendingDiagnosis) {
            $record->update([
                'coverage_decision' => $record->warranty_status === WarrantyStatus::Covered
                    ? WarrantyClaimDecision::FullyCovered
                    : WarrantyClaimDecision::ThirdPartyWarranty,
                'coverage_source' => $record->warranty_status === WarrantyStatus::Covered
                    ? WarrantyCoverageSource::SellerWarranty
                    : WarrantyCoverageSource::ManufacturerWarranty,
                'coverage_reason' => $reason,
                'customer_coverage_explanation' => $reason,
                'coverage_decided_at' => now(),
                'coverage_decided_by' => $user->getKey(),
            ]);
            $record->refresh();
        }

        Gate::forUser($user)->authorize('bill', $record);

        if (mb_trim($reason) === '') {
            throw InvalidBillingTransition::reasonRequired();
        }

        $this->assertBillable($record);

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $record->update([
                'billing_type' => MaintenanceBillingType::WarrantyCovered,
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($record)
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
                    'coverage_decision' => $record->coverage_decision->value,
                    'coverage_source' => $record->coverage_source?->value,
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

        $billingType = match ($record->coverage_decision) {
            WarrantyClaimDecision::FullyCovered => MaintenanceBillingType::WarrantyCovered,
            WarrantyClaimDecision::Goodwill => MaintenanceBillingType::GoodwillCovered,
            WarrantyClaimDecision::ThirdPartyWarranty => MaintenanceBillingType::ThirdPartyCovered,
            WarrantyClaimDecision::ServiceContract => MaintenanceBillingType::ServiceContractCovered,
            default => throw ValidationException::withMessages([
                'coverage_decision' => 'This coverage decision still requires customer billing or approval.',
            ]),
        };

        if (
            $record->coverage_decision === WarrantyClaimDecision::FullyCovered
            && $record->coverage_source === WarrantyCoverageSource::SellerWarranty
            && $record->warranty_status !== WarrantyStatus::Covered
        ) {
            throw ValidationException::withMessages([
                'coverage_decision' => 'Seller-warranty settlement requires an active warranty entitlement.',
            ]);
        }

        $this->assertBillable($record);

        return DB::transaction(function () use ($record, $user, $reason, $billingType): MaintenanceRecord {
            $record->update([
                'billing_type' => $billingType,
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($user)
                ->withChanges([
                    'old' => ['billing_type' => MaintenanceBillingType::Unbilled->value],
                    'attributes' => ['billing_type' => $billingType->value, 'reason' => $reason],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => $reason,
                    'coverage_decision' => $record->coverage_decision->value,
                    'coverage_source' => $record->coverage_source?->value,
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

        $this->assertBillable($record);
        $record->loadMissing('ticket.paymentLink');

        if ($record->ticket === null || $record->ticket->paymentLink?->status !== PaymentLinkStatus::Settled) {
            throw ValidationException::withMessages([
                'record' => 'The linked ticket does not have a settled support payment.',
            ]);
        }

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $record->update([
                'billing_type' => MaintenanceBillingType::TicketSettled,
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($user)
                ->withChanges([
                    'old' => ['billing_type' => MaintenanceBillingType::Unbilled->value],
                    'attributes' => ['billing_type' => MaintenanceBillingType::TicketSettled->value, 'reason' => $reason],
                ])
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'reason' => $reason,
                    'ticket_id' => $record->ticket_id,
                    'ticket_payment_link_id' => $record->ticket->paymentLink->getKey(),
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

        if ($record->billing_type !== MaintenanceBillingType::WarrantyCovered) {
            throw InvalidBillingTransition::alreadyBilled($record->billing_type->value);
        }

        return DB::transaction(function () use ($record, $user, $reason): MaintenanceRecord {
            $record->update([
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
                ->performedOn($record)
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
        $this->assertQuotable($record);

        $lines = $this->customerResponsibilityLines($record);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'record' => 'There is no customer responsibility to quote for this coverage decision.',
            ]);
        }

        return DB::transaction(function () use ($record, $user, $lines): Quotation {
            $quotation = $this->quotationService->create(
                [
                    'customer_id' => $record->customer_id,
                    'issue_date' => now()->toDateString(),
                    'notes' => sprintf(
                        'Customer responsibility for maintenance request #%d after coverage assessment.',
                        $record->id,
                    ),
                ],
                $lines,
            );

            $record->update([
                'billing_type' => MaintenanceBillingType::Quoted,
                'quotation_id' => $quotation->getKey(),
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($user)
                ->withProperties([
                    'source_channel' => 'dashboard',
                    'quotation_id' => $quotation->getKey(),
                    'customer_responsibility_minor' => (int) $record->coverageLines()->sum('customer_amount_minor'),
                ])
                ->log('support.maintenance_record.quoted');

            return $quotation;
        });
    }

    public function createInvoice(MaintenanceRecord $record, User $user): Invoice
    {
        Gate::forUser($user)->authorize('bill', $record);
        $this->assertInvoiceable($record);

        return DB::transaction(function () use ($record, $user): Invoice {
            $hasCoverageAssessment = $record->coverageLines()->exists()
                || in_array($record->coverage_decision, [
                    WarrantyClaimDecision::PartiallyCovered,
                    WarrantyClaimDecision::Rejected,
                    WarrantyClaimDecision::FullyCovered,
                    WarrantyClaimDecision::Goodwill,
                    WarrantyClaimDecision::ThirdPartyWarranty,
                    WarrantyClaimDecision::ServiceContract,
                ], true);

            $lines = $hasCoverageAssessment
                ? $this->customerResponsibilityLines($record)
                : [...$this->partsLines($record), ...$this->labourLine($record)];

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'record' => 'This maintenance request has no priceable parts or labour to bill.',
                ]);
            }

            $invoice = $this->invoiceService->createStandalone($user, [
                'customer_id' => $record->customer_id,
                'description' => sprintf('Service job #%d', $record->id),
            ], $lines);

            $invoice->forceFill(['maintenance_record_id' => $record->id])->save();

            $record->update([
                'billing_type' => MaintenanceBillingType::Invoiced,
                'invoice_id' => $invoice->getKey(),
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($user)
                ->withProperties(['source_channel' => 'dashboard', 'invoice_id' => $invoice->getKey()])
                ->log('support.maintenance_record.invoiced');

            DB::afterCommit(static fn () => MaintenanceRecordBilled::dispatch($record->refresh()));

            return $invoice->refresh();
        });
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
            $record->loadMissing('quotation');

            if ($record->quotation?->status !== QuotationStatus::Accepted) {
                throw ValidationException::withMessages([
                    'record' => 'The customer-responsibility quotation must be accepted before creating the final invoice.',
                ]);
            }

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

    /**
     * Converts the post-coverage customer share into ordinary Sales service
     * lines. When no coverage assessment exists yet, keep the legacy support
     * billing path so existing jobs remain billable without backfilling claims.
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
                return [...$this->partsLines($record), ...$this->labourLine($record)];
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
}
