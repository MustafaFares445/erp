<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Schemas;

use App\Enums\StockCondition;
use App\Filament\Components\InventoryViewDiagnostics;
use App\Models\InventoryCount;
use App\Models\ProductVariant;
use App\Services\Inventory\Diagnostics\InventoryCountDiagnoser;
use App\Services\Inventory\InventoryCountService;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The reviewable variance worksheet (WP-3.5, GAP-MW-06, IN-06) — per-grain
 * system/counted/variance/value, materiality-flagged exceptions first, with
 * an explicit "unvalued" marker rather than a silent zero where no cost is
 * on record. Sourced entirely from {@see InventoryCountService::variance()};
 * this schema computes nothing itself.
 */
final class InventoryCountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            InventoryViewDiagnostics::section(),
            InventoryViewDiagnostics::metrics(
                static fn (InventoryCount $record): array => app(InventoryCountDiagnoser::class)->keyFigures($record),
            ),
            Section::make(__('admin.inventory.count.sections.count'))
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema([
                    TextEntry::make('count_number')->label(__('admin.inventory.count.fields.count')),
                    TextEntry::make('warehouse.name')->label(__('admin.inventory.count.fields.warehouse')),
                    TextEntry::make('scope_type')->label(__('admin.inventory.count.fields.scope'))->badge(),
                    TextEntry::make('status')->label(__('admin.inventory.count.fields.status'))->badge(),
                    IconEntry::make('is_partial')->label(__('admin.inventory.count.fields.partial'))->boolean(),
                    TextEntry::make('materiality_threshold_minor')->label(__('admin.inventory.count.fields.variance_threshold'))->placeholder(__('—')),
                    TextEntry::make('counter.name')->label(__('admin.inventory.count.fields.counted_by'))->placeholder(__('—')),
                    TextEntry::make('confirmedBy.name')->label(__('admin.inventory.count.fields.confirmed_by'))->placeholder(__('—')),
                    TextEntry::make('opened_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('closed_at')->dateTime()->placeholder(__('—')),
                    TextEntry::make('adjustment.adjustment_number')->label(__('admin.inventory.count.fields.adjustment'))->placeholder(__('—')),
                ]),
            Section::make(__('admin.inventory.count.sections.progress'))
                ->columns(5)
                ->schema([
                    TextEntry::make('total_lines')
                        ->label(__('admin.inventory.count.fields.total_lines'))
                        ->state(fn (InventoryCount $record): int => $record->lines()->count()),
                    TextEntry::make('counted_lines')
                        ->label(__('admin.inventory.count.fields.counted_lines'))
                        ->state(fn (InventoryCount $record): int => $record->lines()->whereNotNull('counted_base_quantity')->count()),
                    TextEntry::make('remaining_lines')
                        ->label(__('admin.inventory.count.fields.remaining_lines'))
                        ->state(fn (InventoryCount $record): int => $record->lines()->whereNull('counted_base_quantity')->count()),
                    TextEntry::make('variance_lines')
                        ->label(__('admin.inventory.count.fields.variance_lines'))
                        ->state(fn (InventoryCount $record): int => $record->lines()
                            ->whereNotNull('counted_base_quantity')
                            ->whereColumn('counted_base_quantity', '!=', 'system_base_quantity')
                            ->count()),
                    TextEntry::make('recount_lines')
                        ->label(__('admin.inventory.count.fields.recount_lines'))
                        ->state(fn (InventoryCount $record): int => $record->lines()->where('recount_requested', true)->count()),
                ]),
            Section::make(__('admin.inventory.count.sections.variance'))
                ->description(__('admin.inventory.count.help.variance'))
                ->schema([
                    RepeatableEntry::make('variance')
                        ->hiddenLabel()
                        ->state(fn (InventoryCount $record): array => self::varianceRows($record))
                        ->schema([
                            TextEntry::make('variant_sku')->label(__('admin.inventory.count.fields.variant')),
                            TextEntry::make('stock_condition')->badge()
                                ->formatStateUsing(fn (?string $state): string => StockCondition::tryFrom((string) $state)?->label() ?? __('—')),
                            TextEntry::make('system_base_quantity')->label(__('admin.inventory.count.fields.system'))
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            TextEntry::make('counted_base_quantity')->label(__('admin.inventory.count.fields.counted'))
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))
                                ->placeholder(__('—')),
                            TextEntry::make('variance_base_quantity')->label(__('admin.inventory.count.fields.variance'))
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state))
                                ->placeholder(__('—')),
                            TextEntry::make('value_display')->label(__('admin.inventory.count.fields.value')),
                            IconEntry::make('recount_requested')->label(__('admin.inventory.count.fields.flagged'))->boolean(),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    /**
     * Variance worksheet rows with a display-ready variant SKU and value.
     *
     * @return list<array<string, mixed>>
     */
    private static function varianceRows(InventoryCount $record): array
    {
        $lines = app(InventoryCountService::class)->variance($record);
        $skus = ProductVariant::query()
            ->whereKey(array_column($lines, 'product_variant_id'))
            ->pluck('sku', 'id');

        return array_map(static function (array $line) use ($skus): array {
            $sku = $skus->get($line['product_variant_id']);
            $line['variant_sku'] = is_string($sku) ? $sku : '#'.$line['product_variant_id'];
            $line['value_display'] = $line['unvalued']
                ? '—'
                : number_format(((int) $line['variance_value_minor']) / 100, 2);

            return $line;
        }, $lines);
    }
}
