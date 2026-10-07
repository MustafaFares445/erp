<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReplenishmentRecommendation;
use App\Enums\StockCondition;
use App\Models\InventoryStock;
use App\Models\WarehouseReplenishmentPolicy;

final class ReplenishmentRecommendationService
{
    /** @var array<int, ReplenishmentRecommendation> */
    private array $cache = [];

    public function __construct(
        private readonly ReplenishmentProjectionService $projections,
    ) {}

    public function recommendation(WarehouseReplenishmentPolicy $policy): ReplenishmentRecommendation
    {
        $policyKey = $policy->getKey();

        if (is_int($policyKey) && isset($this->cache[$policyKey])) {
            return $this->cache[$policyKey];
        }

        $projection = $this->projections->project($policy);
        $stock = InventoryStock::query()
            ->where('warehouse_id', $policy->warehouse_id)
            ->where('product_variant_id', $policy->product_variant_id)
            ->first();
        $reserved = $stock instanceof InventoryStock
            ? $stock->conditionReservedQuantity(StockCondition::Saleable)
            : 0.0;
        $minimum = (float) $policy->min_quantity;
        $maximum = (float) $policy->max_quantity;
        $projected = $projection->projectedStock();
        $suggested = $projected <= $minimum
            ? max(0.0, round($maximum - $projected, 6))
            : 0.0;
        $reference = $policy->resolvePreferredPurchasingReference();

        $recommendation = new ReplenishmentRecommendation(
            available: $projection->saleableAvailable,
            reserved: $reserved,
            incoming: $projection->totalConfirmedIncoming(),
            minimum: $minimum,
            maximum: $maximum,
            projected: $projected,
            suggestedBaseQuantity: $suggested,
            supplierProductReferenceId: $this->integerKey($reference?->getKey()),
            supplierId: $reference?->supplier_id,
            supplierName: $reference?->supplier?->name,
            leadTimeDays: $reference !== null
                ? ($reference->lead_time_days ?? $reference->supplier?->default_lead_time_days)
                : null,
            currencyCode: $reference?->currency_code,
        );

        if (is_int($policyKey)) {
            $this->cache[$policyKey] = $recommendation;
        }

        return $recommendation;
    }

    private function integerKey(mixed $key): ?int
    {
        return is_int($key) ? $key : null;
    }
}
