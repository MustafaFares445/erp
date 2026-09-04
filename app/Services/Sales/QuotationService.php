<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Events\QuotationDecided;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Inventory\PriceResolver;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class QuotationService
{
    public function __construct(
        private PriceResolver $priceResolver,
        private QuantityNormalizer $quantityNormalizer,
        private LineTotalCalculator $calculator,
        private DocumentNumberGenerator $numberGenerator,
        private OpportunityService $opportunityService,
    ) {}

    /** @param array<string, mixed> $attributes @param list<array{product_variant_id:int, quantity:float|int|string, unit_id?:int|null, unit_price?:float|null, tax_amount?:float|null, description?:string|null}> $lines */
    public function create(array $attributes, array $lines): Quotation
    {
        return DB::transaction(function () use ($attributes, $lines): Quotation {
            $settings = SalesSetting::current();
            $quotation = new Quotation($attributes);
            $quotation->quotation_number = $this->numberGenerator->next(Quotation::withTrashed(), 'quotation_number', 'QT-');
            $quotation->expires_at ??= Carbon::today()->addDays($settings->default_quotation_validity_days);
            $quotation->status = QuotationStatus::Draft;
            $quotation->save();
            $this->syncLines($quotation, $lines, $settings);
            return $quotation->refresh();
        });
    }

    public function createFromOpportunity(SalesOpportunity $opportunity): Quotation
    {
        if (! $opportunity->isQuotable()) { throw OpportunityNotQuotable::notApproved(); }
        $existing = Quotation::query()->where('sales_opportunity_id', $opportunity->getKey())->first();
        if ($existing instanceof Quotation) { throw OpportunityNotQuotable::alreadyQuoted((string) $existing->quotation_number); }
        $customer = $opportunity->resolvedCustomer();
        if (! $customer instanceof CustomerProfile) { throw OpportunityNotQuotable::noCustomer(); }

        return $this->create([
            'customer_id' => $customer->getKey(),
            'employee_id' => $opportunity->resolvedEmployee()?->getKey(),
            'sales_opportunity_id' => $opportunity->getKey(),
            'issue_date' => Carbon::today()->toDateString(),
            'notes' => $opportunity->summary,
            'opportunity_title_snapshot' => $opportunity->title,
            'opportunity_estimated_value_minor_snapshot' => $opportunity->estimated_value_minor,
        ], []);
    }

    /** @param array<string, mixed> $attributes */ public function update(Quotation $quotation, array $attributes): Quotation { $quotation->update($attributes); return $quotation->refresh(); }
    /** @param list<array{product_variant_id:int, quantity:float|int|string, unit_id?:int|null, unit_price?:float|null, tax_amount?:float|null, description?:string|null}> $lines */
    public function updateLines(Quotation $quotation, array $lines): Quotation { return DB::transaction(function () use ($quotation, $lines): Quotation { $this->syncLines($quotation, $lines, SalesSetting::current()); return $quotation->refresh(); }); }

    public function send(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) { throw InvalidQuotationTransition::notSent((string) $quotation->quotation_number); }
        $quotation->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        return $quotation->refresh();
    }

    public function recordDecision(Quotation $quotation, QuotationDecision $decision, CarbonInterface $decidedAt, ?string $note, User $recordedBy): Quotation
    {
        if ($quotation->status !== QuotationStatus::Sent) { throw InvalidQuotationTransition::notSent((string) $quotation->quotation_number); }
        if ($decision === QuotationDecision::Accepted && $quotation->isExpired()) {
            $quotation->update(['status' => QuotationStatus::Expired]);
            throw InvalidQuotationTransition::expired((string) $quotation->quotation_number, (string) $quotation->expires_at?->toDateString());
        }
        $quotation->update(['status' => $decision->resultingStatus(), 'decided_at' => $decidedAt->toDateString(), 'decision_note' => $note, 'decided_by' => $recordedBy->getKey()]);
        $quotation->refresh()->load(['employee.user', 'salesOpportunity', 'decidedBy']);
        if ($decision === QuotationDecision::Accepted) { $this->opportunityService->closeWonFromQuotation($quotation); }
        else { $this->opportunityService->closeLostOnQuotationRejection($quotation, $note ?? 'Quotation rejected'); }
        QuotationDecided::dispatch($quotation);
        return $quotation;
    }

    /** @param list<array{product_variant_id:int, quantity:float|int|string, unit_id?:int|null, unit_price?:float|null, tax_amount?:float|null, description?:string|null}> $lines */
    private function syncLines(Quotation $quotation, array $lines, SalesSetting $settings): void
    {
        $quotation->lines()->delete(); $totals = []; $customer = $quotation->customer?->user;
        foreach ($lines as $index => $line) {
            $variant = ProductVariant::query()->findOrFail($line['product_variant_id']);
            $unitId = $this->saleUnitId($variant, $line['unit_id'] ?? null);
            $snapshot = $this->quantityNormalizer->normalize($variant, $unitId, $this->quantityInput($line['quantity']));
            $quantity = $snapshot->transactionQuantity;
            if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null) {
                $unitPrice = (float) $line['unit_price']; $priceSource = null;
                $baseEquivalentUnitPrice = $unitPrice / (float) $snapshot->conversionFactorSnapshot;
                $this->priceResolver->assertAtOrAboveFloor($variant, $baseEquivalentUnitPrice);
            } else {
                $resolved = $this->priceResolver->resolve($variant, $customer);
                $unitPrice = round($resolved->amount * (float) $snapshot->conversionFactorSnapshot, 2);
                $priceSource = $resolved->source->value;
            }
            $taxAmount = $line['tax_amount'] ?? $this->calculator->defaultTax((float) $quantity, $unitPrice, (float) $settings->default_tax_percent);
            $lineTotal = $this->calculator->lineTotal((float) $quantity, $unitPrice, $taxAmount);
            $quotation->lines()->create([
                'product_variant_id' => $variant->getKey(), 'unit_id' => $snapshot->transactionUnitId, 'description' => $line['description'] ?? null,
                'quantity' => $quantity, 'transaction_quantity' => $snapshot->transactionQuantity, 'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot, 'base_quantity' => $snapshot->baseQuantity,
                'unit_price' => $unitPrice, 'tax_amount' => $taxAmount, 'line_total' => $lineTotal, 'resolved_price_source' => $priceSource, 'sort_order' => $index,
            ]);
            $totals[] = ['subtotal' => round((float) $quantity * $unitPrice, 2), 'tax_amount' => $taxAmount, 'line_total' => $lineTotal];
        }
        $quotation->update($this->calculator->documentTotals($totals));
    }

    private function saleUnitId(ProductVariant $variant, mixed $requestedUnitId): int
    {
        if ($requestedUnitId !== null) {
            if (! is_numeric($requestedUnitId)) { throw ValidationException::withMessages(['unit_id' => 'Select a valid active sales unit for this variant.']); }
            $unitId = (int) $requestedUnitId;
            if (! $variant->variantUnits()->where('unit_id', $unitId)->where('is_active', true)->where('is_sale', true)->exists()) { throw ValidationException::withMessages(['unit_id' => 'Select a valid active sales unit for this variant.']); }
            return $unitId;
        }
        $unitId = $variant->variantUnits()->where('is_active', true)->where('is_sale', true)->orderByDesc('is_base')->value('unit_id');
        if (! is_numeric($unitId)) { throw ValidationException::withMessages(['unit_id' => 'The selected variant has no active sales unit.']); }
        return (int) $unitId;
    }

    private function quantityInput(mixed $quantity): string|int
    {
        if (is_int($quantity) || is_string($quantity)) { return $quantity; }
        if (is_float($quantity) && is_finite($quantity)) { return mb_rtrim(mb_rtrim(number_format($quantity, 6, '.', ''), '0'), '.'); }
        throw ValidationException::withMessages(['quantity' => 'The quotation quantity must be numeric.']);
    }
}
