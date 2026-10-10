<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\StockCondition;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryStock;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * Inventory-owned stock summary on the (Catalog-owned) product View page: totals across all
 * variants and warehouses, and each variant's tracking requirements next to its stock.
 */
final class ProductStockSummary
{
    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    public static function metrics(Product $product): array
    {
        $variantIds = ProductVariant::query()->where('product_id', $product->getKey())->pluck('id');
        $activeVariants = ProductVariant::query()->whereKey($variantIds)->where('is_active', true)->count();
        $totals = InventoryStock::query()
            ->whereIn('product_variant_id', $variantIds)
            ->toBase()
            ->selectRaw('coalesce(sum(on_hand_quantity), 0) as on_hand')
            ->selectRaw('coalesce(sum(reserved_quantity), 0) as reserved')
            ->selectRaw('coalesce(sum(available_quantity), 0) as available')
            ->selectRaw('count(distinct case when on_hand_quantity > 0 then warehouse_id end) as warehouses')
            ->first();
        $restricted = (float) InventoryConditionBalance::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('stock_condition', [StockCondition::Quarantine->value, StockCondition::Damaged->value])
            ->sum('on_hand_base_quantity');
        $available = (float) ($totals->available ?? 0);

        return [
            [
                'label' => __('inventory_view_ux.ui.variants'),
                'value' => (string) $variantIds->count(),
                'helper' => __('inventory_view_ux.ui.active_count', ['count' => $activeVariants]),
            ],
            [
                'label' => __('inventory_view_ux.ui.warehouses_with_stock'),
                'value' => (string) (int) ($totals->warehouses ?? 0),
            ],
            [
                'label' => __('admin.inventory.stock.on_hand_quantity'),
                'value' => QuantityFormatter::display($totals->on_hand ?? 0),
            ],
            [
                'label' => __('admin.inventory.stock.available_quantity'),
                'value' => QuantityFormatter::display($available),
                'tone' => $available <= 0 && (float) ($totals->on_hand ?? 0) > 0 ? 'danger' : null,
            ],
            [
                'label' => __('admin.inventory.stock.reserved_quantity'),
                'value' => QuantityFormatter::display($totals->reserved ?? 0),
            ],
            [
                'label' => __('inventory_view_ux.ui.restricted'),
                'value' => QuantityFormatter::display($restricted),
                'helper' => __('inventory_view_ux.ui.restricted_helper'),
                'tone' => $restricted > 0 ? 'warning' : null,
            ],
        ];
    }

    public static function variantsSection(): Section
    {
        return Section::make(__('inventory_view_ux.ui.variants_and_tracking'))
            ->description(__('inventory_view_ux.ui.variants_and_tracking_description'))
            ->schema([
                RepeatableEntry::make('inventory_variant_summary')
                    ->hiddenLabel()
                    ->state(static fn (Product $record): array => self::variantRows($record))
                    ->placeholder(__('inventory_view_ux.ui.no_variants'))
                    ->columns(['default' => 2, 'md' => 3, 'xl' => 6])
                    ->schema([
                        TextEntry::make('sku')->label(__('admin.inventory.stock.variant')),
                        TextEntry::make('tracking')->label(__('inventory_view_ux.ui.tracking'))->badge()->color('gray'),
                        TextEntry::make('expiry')->label(__('inventory_view_ux.ui.expiry_tracking')),
                        TextEntry::make('status')
                            ->label(__('inventory_view_ux.ui.variant_status'))
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('on_hand')->label(__('admin.inventory.stock.on_hand_quantity')),
                        TextEntry::make('available')->label(__('admin.inventory.stock.available_quantity')),
                    ]),
            ]);
    }

    /**
     * @return list<array{sku: string, tracking: string, expiry: string, status: string, on_hand: string, available: string}>
     */
    private static function variantRows(Product $product): array
    {
        $variants = ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->with('unit:id,code,symbol')
            ->orderBy('sku')
            ->get();
        $stock = InventoryStock::query()
            ->whereIn('product_variant_id', $variants->modelKeys())
            ->toBase()
            ->groupBy('product_variant_id')
            ->select('product_variant_id')
            ->selectRaw('coalesce(sum(on_hand_quantity), 0) as on_hand')
            ->selectRaw('coalesce(sum(available_quantity), 0) as available')
            ->get()
            ->keyBy('product_variant_id');

        return $variants->map(static function (ProductVariant $variant) use ($stock, $product): array {
            $variant->setRelation('product', $product);
            $row = $stock->get($variant->getKey());
            $unit = $variant->unit !== null ? ' '.($variant->unit->symbol ?: $variant->unit->code) : '';

            return [
                'sku' => $variant->sku,
                'tracking' => $variant->trackingMode()->label(),
                'expiry' => $variant->tracksExpirationConfigured() ? __('inventory_view_ux.ui.expiry_required') : __('inventory_view_ux.ui.expiry_not_tracked'),
                'status' => $variant->is_active ? __('inventory_view_ux.ui.active') : __('inventory_view_ux.ui.inactive'),
                'on_hand' => QuantityFormatter::display($row->on_hand ?? 0).$unit,
                'available' => QuantityFormatter::display($row->available ?? 0).$unit,
            ];
        })->values()->all();
    }
}
