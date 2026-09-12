<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Events\MaintenanceRecordBilled;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesSetting;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Sales\InvoiceService;
use App\Services\Sales\LineTotalCalculator;
use App\Services\Sales\PriceResolver;
use App\Services\Sales\QuotationService;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Turns a closed, chargeable service job into a quotation or invoice
 * (WP-2.9, GAP-MW-10, F-06) by delegating to the existing Sales services —
 * {@see QuotationService::create()} and {@see InvoiceService::createStandalone()}
 * — so a service invoice follows exactly the same issue/collect/tax path as a
 * goods invoice ("a second revenue path would be a second tax policy").
 *
 * A job may be billed at most once: {@see MaintenanceRecord::$billing_type}
 * moves from `Unbilled` to a settled state and never back, except through
 * {@see self::reclassifyWarrantyForBilling()}'s explicit, audited escape
 * hatch for a warranty-covered job.
 */
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
                    'attributes' => ['billing_type' => MaintenanceBillingType::WarrantyCovered->value, 'reason' => $reason],
                ])
                ->withProperties(['source_channel' => 'dashboard', 'reason' => $reason])
                ->log('support.maintenance_record.warranty_covered');

            return $record->refresh();
        });
    }

    /**
     * Reclassifies a warranty-covered job back to `Unbilled` so it can be
     * quoted or invoiced after all — an audited, reasoned override, never a
     * silent reset (GAP-MW-10's warranty guard).
     */
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
        $this->assertBillable($record);

        return DB::transaction(function () use ($record, $user): Quotation {
            $quotation = $this->quotationService->create(
                ['customer_id' => $record->customer_id],
                $this->partsLines($record),
            );

            $record->update([
                'billing_type' => MaintenanceBillingType::Quoted,
                'quotation_id' => $quotation->getKey(),
                'billed_at' => now(),
            ]);

            activity()
                ->performedOn($record)
                ->causedBy($user)
                ->withProperties(['source_channel' => 'dashboard', 'quotation_id' => $quotation->getKey()])
                ->log('support.maintenance_record.quoted');

            return $quotation;
        });
    }

    public function createInvoice(MaintenanceRecord $record, User $user): Invoice
    {
        Gate::forUser($user)->authorize('bill', $record);
        $this->assertBillable($record);

        return DB::transaction(function () use ($record, $user): Invoice {
            $lines = [...$this->partsLines($record), ...$this->labourLine($record)];

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

    /**
     * Every un-reversed consumed part, priced through the standard
     * {@see PriceResolver} path at the job's customer's sale price — the
     * resolved amount is passed through as `unit_price` so
     * {@see QuotationService} and {@see InvoiceService} snapshot its price
     * provenance exactly as any other manually-confirmed price does, and its
     * tax follows the module's own default-tax policy rather than defaulting
     * to zero the way an untaxed standalone line otherwise would.
     *
     * @return list<array{product_variant_id:int, quantity:float, unit_price:float, tax_amount:float}>
     */
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

    /**
     * The job's labour, billed as a single service line at its recorded
     * rate — an explicit `unit_price` is required here because
     * {@see InvoiceService::createStandalone()} treats a line without a
     * `product_variant_id` as a service line, not a priced product.
     *
     * @return list<array{description:string, quantity:int, unit_price:float, tax_amount:float}>
     */
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
