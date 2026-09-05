<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\QuotationDecision;
use App\Enums\QuotationStatus;
use App\Enums\ReservationStatus;
use App\Events\QuotationDecided;
use App\Events\QuotationExpired;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\SalesOpportunity;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Inventory\InventoryReservationService;
use App\Services\Inventory\PriceResolver;
use App\Services\Inventory\QuantityNormalizer;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
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
        private PriceProvenanceService $priceProvenance,
        private InventoryReservationService $reservationService,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{product_variant_id:int, quantity:float|int|string, unit_id?:int|null, unit_price?:float|null, tax_amount?:float|null, description?:string|null, price_floor_override_id?:int|null}>  $lines
     */
    public function create(array $attributes, array $lines): Quotation
    {
        return DB::transaction(function () use ($attributes, $lines): Quotation {
            $settings = SalesSetting::current();
            $quotation = new Quotation($attributes);
            $quotation->quotation_number = $this->numberGenerator->next(
                Quotation::withTrashed(),
                'quotation_number',
                'QT-',
            );
            $quotation->expires_at ??= Carbon::today()->addDays($settings->default_quotation_validity_days);
            $quotation->status = QuotationStatus::Draft;
            $quotation->save();

            $this->syncLines($quotation, $lines, $settings);

            return $quotation->refresh();
        });
    }

    public function createFromOpportunity(SalesOpportunity $opportunity): Quotation
    {
        if (! $opportunity->isQuotable()) {
            throw OpportunityNotQuotable::notApproved();
        }

        $existing = Quotation::query()
            ->where('sales_opportunity_id', $opportunity->getKey())
            ->first();

        if ($existing instanceof Quotation) {
            throw OpportunityNotQuotable::alreadyQuoted((string) $existing->quotation_number);
        }

        $customer = $opportunity->resolvedCustomer();
        if (! $customer instanceof CustomerProfile) {
            throw OpportunityNotQuotable::noCustomer();
        }

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

    /** @param array<string, mixed> $attributes */
    public function update(Quotation $quotation, array $attributes): Quotation
    {
        $quotation->update($attributes);

        return $quotation->refresh();
    }

    /**
     * @param  list<array{product_variant_id:int, quantity:float|int|string, unit_id?:int|null, unit_price?:float|null, tax_amount?:float|null, description?:string|null, price_floor_override_id?:int|null}>  $lines
     */
    public function updateLines(Quotation $quotation, array $lines): Quotation
    {
        return DB::transaction(function () use ($quotation, $lines): Quotation {
            $this->syncLines($quotation, $lines, SalesSetting::current());

            return $quotation->refresh();
        });
    }

    public function send(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Draft) {
            throw InvalidQuotationTransition::notSent((string) $quotation->quotation_number);
        }

        $quotation->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);

        return $quotation->refresh();
    }

    public function recordDecision(
        Quotation $quotation,
        QuotationDecision $decision,
        CarbonInterface $decidedAt,
        ?string $note,
        User $recordedBy,
    ): Quotation {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw InvalidQuotationTransition::notSent((string) $quotation->quotation_number);
        }

        if ($decision === QuotationDecision::Accepted && $quotation->isExpired()) {
            $quotation->update(['status' => QuotationStatus::Expired]);

            throw InvalidQuotationTransition::expired(
                (string) $quotation->quotation_number,
                (string) $quotation->expires_at?->toDateString(),
            );
        }

        $quotation->update([
            'status' => $decision->resultingStatus(),
            'decided_at' => $decidedAt->toDateString(),
            'decision_note' => $note,
            'decided_by' => $recordedBy->getKey(),
        ]);
        $quotation->refresh()->load(['employee.user', 'salesOpportunity', 'decidedBy']);

        if ($decision === QuotationDecision::Accepted) {
            $this->opportunityService->closeWonFromQuotation($quotation);
        } else {
            $this->opportunityService->closeLostOnQuotationRejection(
                $quotation,
                $note ?? 'Quotation rejected',
            );
        }

        QuotationDecided::dispatch($quotation);

        return $quotation;
    }

    /**
     * Transitions a lapsed `Sent` quotation to `Expired` — the sweep's only
     * entry point (GAP-BW-07), so the audit entry and reservation release
     * always run alongside the status write. A mass `update()` would skip
     * both.
     */
    public function expire(Quotation $quotation): Quotation
    {
        if ($quotation->status !== QuotationStatus::Sent) {
            throw InvalidQuotationTransition::notSent((string) $quotation->quotation_number);
        }

        return DB::transaction(function () use ($quotation): Quotation {
            $quotation->update(['status' => QuotationStatus::Expired]);
            $quotation->refresh();

            $this->releaseReservationsFor($quotation);

            activity()
                ->performedOn($quotation)
                ->withChanges([
                    'old' => ['status' => QuotationStatus::Sent->value],
                    'attributes' => ['status' => QuotationStatus::Expired->value],
                ])
                ->withProperties(['source_channel' => 'scheduler'])
                ->log('quotation.expired');

            QuotationExpired::dispatch($quotation);

            return $quotation;
        });
    }

    /**
     * Clones an expired quotation into a new `Draft`, re-resolving every
     * line's price against current policy (SL-16) rather than copying the
     * frozen, possibly stale amounts, and linking the clone back to the
     * original via `requoted_from_id` (F-18).
     */
    public function requote(Quotation $quotation): Quotation
    {
        if (! $quotation->isExpired()) {
            throw InvalidQuotationTransition::notExpired((string) $quotation->quotation_number);
        }

        /** @var list<array{product_variant_id:int, quantity:float|int|string, unit_id:int|null, description:string|null}> $lines */
        $lines = $quotation->lines
            ->map(fn (QuotationLine $line): array => [
                'product_variant_id' => (int) $line->product_variant_id,
                'quantity' => (string) $line->quantity,
                'unit_id' => $line->unit_id,
                'description' => $line->description,
            ])
            ->all();

        return $this->create([
            'customer_id' => $quotation->customer_id,
            'employee_id' => $quotation->employee_id,
            'payment_term_id' => $quotation->payment_term_id,
            'issue_date' => Carbon::today()->toDateString(),
            'notes' => $quotation->notes,
            'requoted_from_id' => $quotation->getKey(),
        ], $lines);
    }

    /**
     * Releases any active reservation the quotation holds via the WP-1.6
     * release path, reasoned as "quotation expired". A plain `Sent`
     * quotation has no reservation of its own today — only a converted
     * order's fulfillment does — but an inventory operation sourced
     * directly from the quotation is checked defensively so this keeps
     * working if that ever changes.
     */
    private function releaseReservationsFor(Quotation $quotation): void
    {
        $operationIds = InventoryOperation::query()
            ->where('source_document_type', Quotation::class)
            ->where('source_document_id', $quotation->getKey())
            ->pluck('id');

        if ($operationIds->isEmpty()) {
            return;
        }

        /** @var Collection<int, InventoryReservation> $reservations */
        $reservations = InventoryReservation::query()
            ->where('source_type', 'inventory_operation')
            ->whereIn('source_id', $operationIds)
            ->where('status', ReservationStatus::Active->value)
            ->get();

        foreach ($reservations as $reservation) {
            $this->reservationService->release($reservation, null, 'quotation expired');
        }
    }

    /**
     * @param  list<array{product_variant_id:int, quantity:float|int|string, unit_id?:int|null, unit_price?:float|null, tax_amount?:float|null, description?:string|null, price_floor_override_id?:int|null}>  $lines
     */
    private function syncLines(Quotation $quotation, array $lines, SalesSetting $settings): void
    {
        $quotation->lines()->delete();
        $totals = [];
        $customer = $quotation->customer?->user;

        foreach ($lines as $index => $line) {
            $variant = ProductVariant::query()->findOrFail($line['product_variant_id']);
            $unitId = $this->saleUnitId($variant, $line['unit_id'] ?? null);
            $snapshot = $this->quantityNormalizer->normalize(
                $variant,
                $unitId,
                $this->quantityInput($line['quantity']),
            );
            $quantity = $snapshot->transactionQuantity;
            $multiplier = (float) $snapshot->conversionFactorSnapshot;

            if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null) {
                $unitPrice = (float) $line['unit_price'];
                $overrideId = isset($line['price_floor_override_id']) && is_numeric($line['price_floor_override_id'])
                    ? (int) $line['price_floor_override_id']
                    : null;
                $provenance = $this->priceProvenance->forManualPrice(
                    $variant,
                    $customer,
                    $unitPrice,
                    $multiplier,
                    $overrideId,
                );
            } else {
                $resolved = $this->priceResolver->resolve($variant, $customer);
                $unitPrice = round($resolved->amount * $multiplier, 2);
                $provenance = $this->priceProvenance->fromResolved($resolved, $multiplier);
            }

            $taxAmount = $line['tax_amount']
                ?? $this->calculator->defaultTax(
                    (float) $quantity,
                    $unitPrice,
                    (float) $settings->default_tax_percent,
                );
            $lineTotal = $this->calculator->lineTotal((float) $quantity, $unitPrice, $taxAmount);

            $quotation->lines()->create([
                'product_variant_id' => $variant->getKey(),
                'unit_id' => $snapshot->transactionUnitId,
                'description' => $line['description'] ?? null,
                'quantity' => $quantity,
                'transaction_quantity' => $snapshot->transactionQuantity,
                'transaction_unit_id' => $snapshot->transactionUnitId,
                'conversion_factor_snapshot' => $snapshot->conversionFactorSnapshot,
                'base_quantity' => $snapshot->baseQuantity,
                'unit_price' => $unitPrice,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                ...$provenance,
                'sort_order' => $index,
            ]);

            $totals[] = [
                'subtotal' => round((float) $quantity * $unitPrice, 2),
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ];
        }

        $quotation->update($this->calculator->documentTotals($totals));
    }

    private function saleUnitId(ProductVariant $variant, mixed $requestedUnitId): int
    {
        if ($requestedUnitId !== null) {
            if (! is_numeric($requestedUnitId)) {
                throw ValidationException::withMessages([
                    'unit_id' => 'Select a valid active sales unit for this variant.',
                ]);
            }

            $unitId = (int) $requestedUnitId;
            if (! $variant->variantUnits()
                ->where('unit_id', $unitId)
                ->where('is_active', true)
                ->where('is_sale', true)
                ->exists()) {
                throw ValidationException::withMessages([
                    'unit_id' => 'Select a valid active sales unit for this variant.',
                ]);
            }

            return $unitId;
        }

        $unitId = $variant->variantUnits()
            ->where('is_active', true)
            ->where('is_sale', true)
            ->orderByDesc('is_base')
            ->value('unit_id');

        if (! is_numeric($unitId)) {
            throw ValidationException::withMessages([
                'unit_id' => 'The selected variant has no active sales unit.',
            ]);
        }

        return (int) $unitId;
    }

    private function quantityInput(mixed $quantity): string|int
    {
        if (is_int($quantity) || is_string($quantity)) {
            return $quantity;
        }

        if (is_float($quantity) && is_finite($quantity)) {
            return mb_rtrim(mb_rtrim(number_format($quantity, 6, '.', ''), '0'), '.');
        }

        throw ValidationException::withMessages([
            'quantity' => 'The quotation quantity must be numeric.',
        ]);
    }
}
