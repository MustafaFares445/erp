<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequirement;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentRecommendationService;
use App\Services\Inventory\ReplenishmentTransferSuggestionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReplenishmentPurchaseOrderDraftService
{
    private const int SCALE = 6;

    public function __construct(
        private PurchaseOrderService $purchaseOrders,
        private ReplenishmentRecommendationService $recommendations,
        private ReplenishmentTransferSuggestionService $transferSuggestions,
    ) {}

    /**
     * Create canonical Purchase Order drafts from selected active replenishment requirements.
     *
     * Requirements are grouped by supplier and currency because a Purchase Order has one
     * supplier/currency header. Warehouse ownership deliberately remains outside the PO:
     * the existing Purchase Inbound allocation workflow decides destination warehouses
     * after commercial acceptance.
     *
     * @param  list<int>  $requirementIds
     * @return Collection<int, PurchaseOrder>
     */
    public function createDrafts(User $actor, array $requirementIds): Collection
    {
        $ids = collect($requirementIds)
            ->filter(static fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages([
                'requirement_ids' => 'Select at least one replenishment recommendation.',
            ]);
        }

        return DB::transaction(function () use ($actor, $ids): Collection {
            /** @var Collection<int, ReplenishmentRequirement> $requirements */
            $requirements = ReplenishmentRequirement::query()
                ->active()
                ->whereKey($ids->all())
                ->with([
                    'policy',
                    'warehouse:id,name',
                    'productVariant.variantUnits.unit',
                ])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($requirements->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'requirement_ids' => 'One or more selected replenishment recommendations are no longer active.',
                ]);
            }

            /**
             * @var array<string, array{
             *     supplier_id:int,
             *     currency_code:string,
             *     lead_time_days:int,
             *     requirement_labels:list<string>,
             *     lines:array<string, array{
             *         variant:ProductVariant,
             *         configuration:ProductVariantUnit,
             *         reference:SupplierProductReference,
             *         base_quantity:numeric-string
             *     }>
             * }> $groups
             */
            $groups = [];

            foreach ($requirements as $requirement) {
                $policy = $requirement->policy;

                if (! $policy instanceof WarehouseReplenishmentPolicy) {
                    throw ValidationException::withMessages([
                        'requirement_ids' => 'A selected replenishment recommendation has no policy.',
                    ]);
                }

                $purchaseBaseQuantity = $this->externalPurchaseBaseQuantity($requirement);

                if (bccomp($purchaseBaseQuantity, '0.000000', self::SCALE) <= 0) {
                    continue;
                }

                $reference = $this->recommendations->preferredReference($policy);

                if (! $reference instanceof SupplierProductReference) {
                    throw ValidationException::withMessages([
                        'requirement_ids' => sprintf(
                            'REQ-%d has no currently valid supplier product reference.',
                            (int) $requirement->getKey(),
                        ),
                    ]);
                }

                $variant = $requirement->productVariant;

                if (! $variant instanceof ProductVariant) {
                    throw ValidationException::withMessages([
                        'requirement_ids' => 'A selected replenishment recommendation has no product variant.',
                    ]);
                }

                $configuration = $this->purchaseConfiguration($variant, $reference);
                $currencyCode = mb_strtoupper((string) $reference->currency_code);
                $groupKey = $reference->supplier_id.'|'.$currencyCode;
                $lineKey = $variant->getKey().'|'.$configuration->unit_id;

                if (! isset($groups[$groupKey])) {
                    $leadTimeDays = $reference->lead_time_days
                        ?? $reference->supplier?->default_lead_time_days
                        ?? 0;

                    $groups[$groupKey] = [
                        'supplier_id' => (int) $reference->supplier_id,
                        'currency_code' => $currencyCode,
                        'lead_time_days' => max(0, (int) $leadTimeDays),
                        'requirement_labels' => [],
                        'lines' => [],
                    ];
                } else {
                    $leadTimeDays = $reference->lead_time_days
                        ?? $reference->supplier?->default_lead_time_days
                        ?? 0;
                    $groups[$groupKey]['lead_time_days'] = max(
                        $groups[$groupKey]['lead_time_days'],
                        max(0, (int) $leadTimeDays),
                    );
                }

                if (! isset($groups[$groupKey]['lines'][$lineKey])) {
                    $groups[$groupKey]['lines'][$lineKey] = [
                        'variant' => $variant,
                        'configuration' => $configuration,
                        'reference' => $reference,
                        'base_quantity' => '0.000000',
                    ];
                }

                $groups[$groupKey]['lines'][$lineKey]['base_quantity'] = bcadd(
                    $groups[$groupKey]['lines'][$lineKey]['base_quantity'],
                    $purchaseBaseQuantity,
                    self::SCALE,
                );

                $groups[$groupKey]['requirement_labels'][] = sprintf(
                    'REQ-%d %s (%s base)',
                    (int) $requirement->getKey(),
                    $requirement->warehouse?->name ?? 'Warehouse',
                    $purchaseBaseQuantity,
                );
            }

            if ($groups === []) {
                throw ValidationException::withMessages([
                    'requirement_ids' => 'The selected recommendations are already covered or can be satisfied by internal transfer suggestions.',
                ]);
            }

            /** @var Collection<int, PurchaseOrder> $orders */
            $orders = new Collection;

            foreach ($groups as $group) {
                $lines = [];

                foreach ($group['lines'] as $line) {
                    $lines[] = [
                        'product_variant_id' => (int) $line['variant']->getKey(),
                        'unit_id' => (int) $line['configuration']->unit_id,
                        'quantity_ordered' => $this->roundedPurchaseQuantity(
                            $line['base_quantity'],
                            $line['configuration'],
                            $line['reference'],
                        ),
                    ];
                }

                $expectedAt = $group['lead_time_days'] > 0
                    ? today()->addDays($group['lead_time_days'])->toDateString()
                    : null;

                $orders->push($this->purchaseOrders->createDraftWithLines(
                    $actor,
                    [
                        'supplier_id' => $group['supplier_id'],
                        'currency_code' => $group['currency_code'],
                        'ordered_at' => today()->toDateString(),
                        'expected_at' => $expectedAt,
                        'notes' => 'Created from replenishment recommendations: '.implode('; ', $group['requirement_labels']),
                    ],
                    $lines,
                ));
            }

            return $orders;
        }, attempts: 5);
    }

    /** @return numeric-string */
    private function externalPurchaseBaseQuantity(ReplenishmentRequirement $requirement): string
    {
        $remaining = number_format($requirement->remainingUncoveredQuantity(), self::SCALE, '.', '');
        $transferQuantity = '0.000000';

        foreach ($this->transferSuggestions->suggest($requirement) as $suggestion) {
            $transferQuantity = bcadd(
                $transferQuantity,
                number_format($suggestion->suggestedBaseQuantity, self::SCALE, '.', ''),
                self::SCALE,
            );
        }

        $purchase = bcsub($remaining, $transferQuantity, self::SCALE);

        return bccomp($purchase, '0.000000', self::SCALE) === 1
            ? $purchase
            : '0.000000';
    }

    private function purchaseConfiguration(
        ProductVariant $variant,
        SupplierProductReference $reference,
    ): ProductVariantUnit {
        $query = $variant->variantUnits()
            ->with('unit')
            ->where('is_active', true)
            ->where('is_purchase', true);

        if ($reference->purchase_unit_id !== null) {
            $configuration = (clone $query)
                ->where('unit_id', (int) $reference->purchase_unit_id)
                ->first();

            if (! $configuration instanceof ProductVariantUnit) {
                throw ValidationException::withMessages([
                    'requirement_ids' => sprintf(
                        'Supplier purchase UoM for variant %s is no longer active.',
                        $variant->sku,
                    ),
                ]);
            }

            return $configuration;
        }

        $configuration = $query
            ->orderByDesc('is_base')
            ->orderBy('factor_to_base')
            ->first();

        if (! $configuration instanceof ProductVariantUnit) {
            throw ValidationException::withMessages([
                'requirement_ids' => sprintf(
                    'Variant %s has no active purchase UoM.',
                    $variant->sku,
                ),
            ]);
        }

        return $configuration;
    }

    /**
     * Round a base requirement upward to a valid purchase-unit increment and MOQ.
     *
     * @param  numeric-string  $baseQuantity
     * @return numeric-string
     */
    private function roundedPurchaseQuantity(
        string $baseQuantity,
        ProductVariantUnit $configuration,
        SupplierProductReference $reference,
    ): string {
        $factor = (string) $configuration->factor_to_base;
        $increment = (string) $configuration->rounding_increment;

        if (
            ! is_numeric($factor)
            || ! is_numeric($increment)
            || bccomp($factor, '0.000000', self::SCALE) <= 0
            || bccomp($increment, '0.000000', self::SCALE) <= 0
        ) {
            throw ValidationException::withMessages([
                'requirement_ids' => 'Purchase UoM conversion and rounding must be positive.',
            ]);
        }

        $target = bcdiv($baseQuantity, $factor, 12);

        if ($reference->minimum_order_quantity !== null) {
            $minimum = (string) $reference->minimum_order_quantity;

            if (is_numeric($minimum) && bccomp($minimum, $target, 12) === 1) {
                $target = $minimum;
            }
        }

        $multiple = bcdiv($target, $increment, 12);
        $wholeMultiple = bcadd($multiple, '0', 0);

        if (bccomp($multiple, $wholeMultiple, 12) === 1) {
            $wholeMultiple = bcadd($wholeMultiple, '1', 0);
        }

        $rounded = bcmul($wholeMultiple, $increment, 12);
        $precision = $configuration->unit?->precision;

        if (! is_int($precision) || $precision < 0 || $precision > self::SCALE) {
            $precision = self::SCALE;
        }

        return bcadd($rounded, '0', $precision);
    }
}
