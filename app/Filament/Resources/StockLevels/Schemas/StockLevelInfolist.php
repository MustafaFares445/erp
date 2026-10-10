<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockLevels\Schemas;

use App\Enums\StockCondition;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Models\InventoryStock;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\StockAvailabilityExplainer;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Stock level View page: Operational health (why stock is or is not available, see
 * StockLevelDiagnoser), key quantities, identity, condition split and replenishment.
 */
final class StockLevelInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(static fn (InventoryStock $record): array => self::metrics($record)),
            Section::make(__('admin.inventory.stock.sections.identity'))
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema([
                    TextEntry::make('productVariant.sku')->label(__('admin.inventory.stock.variant')),
                    TextEntry::make('productVariant.name')->label(__('admin.inventory.stock.variant_name')),
                    TextEntry::make('warehouse.name')->label(__('admin.inventory.stock.warehouse_name')),
                    TextEntry::make('warehouse.code')->label(__('admin.inventory.stock.warehouse')),
                ]),
            Section::make(__('admin.inventory.stock.sections.conditions'))
                ->description(__('inventory_view_ux.ui.stock_conditions_description'))
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->schema([
                    TextEntry::make('saleable_quantity')
                        ->label(__('admin.inventory.stock.saleable_quantity'))
                        ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Saleable))
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                    TextEntry::make('reserved_quantity')
                        ->label(__('admin.inventory.stock.reserved_quantity'))
                        ->state(fn (InventoryStock $record): float => $record->conditionReservedQuantity(StockCondition::Saleable))
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                    TextEntry::make('quarantine_quantity')
                        ->label(__('admin.inventory.stock.quarantine_quantity'))
                        ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Quarantine))
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                    TextEntry::make('damaged_quantity')
                        ->label(__('admin.inventory.stock.damaged_quantity'))
                        ->state(fn (InventoryStock $record): float => $record->conditionOnHandQuantity(StockCondition::Damaged))
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                ]),
            Section::make(__('admin.inventory.stock.sections.planning'))
                ->description(__('inventory_view_ux.ui.stock_planning_description'))
                ->columns(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->schema([
                    TextEntry::make('health')
                        ->label(__('inventory_view_ux.ui.replenishment_status'))
                        ->state(fn (InventoryStock $record): string => self::replenishmentStatus($record))
                        ->badge()
                        ->color(fn (InventoryStock $record): string => match (self::replenishmentState($record)) {
                            'below_minimum' => 'danger',
                            'above_minimum' => 'success',
                            default => 'gray',
                        }),
                    TextEntry::make('reorder_level')
                        ->label(__('admin.inventory.stock.reorder_level'))
                        ->state(fn (InventoryStock $record): ?string => $record->replenishmentPolicy()?->min_quantity)
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))
                        ->placeholder(__('—')),
                    TextEntry::make('maximum_quantity')
                        ->label(__('admin.inventory.stock.maximum_quantity'))
                        ->state(fn (InventoryStock $record): ?string => $record->replenishmentPolicy()?->max_quantity)
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))
                        ->placeholder(__('—')),
                    TextEntry::make('shortage_to_target')
                        ->label(__('admin.inventory.stock.shortage_to_target'))
                        ->state(function (InventoryStock $record): ?float {
                            $target = $record->replenishmentPolicy()?->max_quantity;

                            return is_numeric($target)
                                ? max(0.0, (float) $target - $record->saleableAvailableQuantity())
                                : null;
                        })
                        ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))
                        ->placeholder(__('—')),
                ]),
            Section::make(__('admin.inventory.stock.availability_breakdown'))
                ->description(__('inventory_view_ux.ui.stock_breakdown_description'))
                ->collapsible()
                ->schema([
                    ViewEntry::make('availability_breakdown')
                        ->hiddenLabel()
                        ->view('filament.inventory.stock-availability-breakdown')
                        ->viewData(fn (InventoryStock $record): array => [
                            'explanation' => app(StockAvailabilityExplainer::class)->explainStock($record),
                        ])
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    private static function metrics(InventoryStock $record): array
    {
        $unit = $record->productVariant?->unit;
        $unitLabel = $unit !== null ? ($unit->symbol ?: $unit->code) : null;
        $available = $record->saleableAvailableQuantity();
        $quarantine = $record->conditionOnHandQuantity(StockCondition::Quarantine);
        $damaged = $record->conditionOnHandQuantity(StockCondition::Damaged);
        $inTransit = $record->inTransitQuantity();

        return [
            [
                'label' => __('admin.inventory.stock.on_hand_quantity'),
                'value' => QuantityFormatter::display($record->on_hand_quantity),
                'helper' => $unitLabel,
            ],
            [
                'label' => __('admin.inventory.stock.available_quantity'),
                'value' => QuantityFormatter::display($available),
                'helper' => $unitLabel,
                'tone' => $available <= 0 ? 'danger' : (self::replenishmentState($record) === 'below_minimum' ? 'warning' : null),
            ],
            [
                'label' => __('admin.inventory.stock.reserved_quantity'),
                'value' => QuantityFormatter::display($record->conditionReservedQuantity(StockCondition::Saleable)),
                'helper' => $unitLabel,
            ],
            [
                'label' => __('admin.inventory.stock.quarantine_quantity'),
                'value' => QuantityFormatter::display($quarantine),
                'helper' => $unitLabel,
                'tone' => $quarantine > 0 ? 'warning' : null,
            ],
            [
                'label' => __('admin.inventory.stock.damaged_quantity'),
                'value' => QuantityFormatter::display($damaged),
                'helper' => $unitLabel,
                'tone' => $damaged > 0 ? 'warning' : null,
            ],
            [
                'label' => __('admin.inventory.stock.in_transit_quantity'),
                'value' => QuantityFormatter::display($inTransit),
                'helper' => $unitLabel,
                'tone' => $inTransit > 0 ? 'primary' : null,
            ],
        ];
    }

    private static function replenishmentStatus(InventoryStock $record): string
    {
        return match (self::replenishmentState($record)) {
            'below_minimum' => __('admin.inventory.stock.low_stock'),
            'above_minimum' => __('inventory_view_ux.ui.above_minimum'),
            'inactive' => __('inventory_view_ux.ui.minimum_inactive'),
            default => __('inventory_view_ux.ui.no_minimum'),
        };
    }

    private static function replenishmentState(InventoryStock $record): string
    {
        $policy = $record->replenishmentPolicy();

        return match (true) {
            ! $policy instanceof WarehouseReplenishmentPolicy => 'no_policy',
            ! $policy->is_active => 'inactive',
            $policy->isBreachedBy($record) => 'below_minimum',
            default => 'above_minimum',
        };
    }
}
