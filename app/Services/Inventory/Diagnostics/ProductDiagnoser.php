<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\StockCondition;
use App\Filament\Resources\Products\ProductResource;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseReplenishmentPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock picture of a catalog product across all of its variants and warehouses. Product
 * configuration (names, tracking, pricing) is Catalog-owned; this diagnoser only reads it.
 */
final readonly class ProductDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    public function supports(Model $record): bool
    {
        return $record instanceof Product;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof Product);

        $variantIds = ProductVariant::query()->where('product_id', $record->getKey())->pluck('id');
        $findings = [];

        if ($variantIds->isEmpty()) {
            $findings[] = $this->finding('product.no_variants', Category::DataQuality, Severity::Warning,
                action: $this->productLink($record, 'variants', 'view_variants'));

            return $this->diagnosis($findings);
        }

        $inactive = ProductVariant::query()->whereKey($variantIds)->where('is_active', false)->count();

        if ($inactive > 0) {
            $findings[] = $this->finding('product.inactive_variants', Category::DataQuality, Severity::Notice, ['count' => $inactive],
                action: $this->productLink($record, 'variants', 'view_variants'));
        }

        $totals = InventoryStock::query()
            ->whereIn('product_variant_id', $variantIds)
            ->toBase()
            ->selectRaw('coalesce(sum(on_hand_quantity), 0) as on_hand')
            ->selectRaw('coalesce(sum(available_quantity), 0) as available')
            ->first();
        $onHand = (float) ($totals->on_hand ?? 0);
        $available = (float) ($totals->available ?? 0);
        $quantities = $this->productLink($record, 'quantities', 'view_quantities');

        if ($onHand <= 0) {
            $findings[] = $this->finding('product.no_stock', Category::Availability, Severity::Notice, action: $quantities);
        } elseif ($available <= 0) {
            $findings[] = $this->finding('product.none_available', Category::Availability, Severity::Warning,
                facts: [
                    $this->fact('on_hand', ['quantity' => $this->quantity($onHand)]),
                    $this->fact('available', ['quantity' => $this->quantity($available)]),
                ],
                action: $quantities);
        }

        $restricted = (float) InventoryConditionBalance::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('stock_condition', [StockCondition::Quarantine->value, StockCondition::Damaged->value])
            ->sum('on_hand_base_quantity');

        if ($restricted > 0) {
            $findings[] = $this->finding('product.restricted', Category::Condition, Severity::Warning,
                ['quantity' => $this->quantity($restricted)], action: $quantities);
        }

        $belowMinimum = InventoryStock::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereExists(WarehouseReplenishmentPolicy::breachedSubquery())
            ->count();

        if ($belowMinimum > 0) {
            $findings[] = $this->finding('product.below_minimum', Category::Replenishment, Severity::Warning,
                ['count' => $belowMinimum], action: $quantities);
        }

        $expiredLots = InventoryLotBalance::query()
            ->where('stock_condition', StockCondition::Saleable->value)
            ->where('on_hand_base_quantity', '>', 0)
            ->whereHas('lot', fn (Builder $query): Builder => $query
                ->whereIn('product_variant_id', $variantIds)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', today()))
            ->distinct()
            ->count('inventory_lot_id');

        if ($expiredLots > 0) {
            $findings[] = $this->finding('product.expired_lots', Category::Expiry, Severity::Warning, ['count' => $expiredLots],
                action: $quantities);
        }

        return $this->diagnosis($findings);
    }

    /**
     * @param  list<InventoryDiagnosticFinding>  $findings
     */
    private function diagnosis(array $findings): InventoryDiagnosis
    {
        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('product_variants'),
                $this->check('product_stock'),
                $this->check('product_replenishment'),
                $this->check('product_tracking'),
            ],
            limits: [
                $this->limit('product_scope'),
                $this->limit('recorded_balances'),
            ],
        );
    }

    private function productLink(Product $product, string $page, string $labelKey): InventoryDiagnosticAction
    {
        return InventoryDiagnosticAction::link(
            $this->actionLabel($labelKey),
            ProductResource::getUrl($page, ['record' => $product]),
            ProductResource::canView($product),
        );
    }
}
