<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Schemas;

use App\Models\InventoryCount;
use App\Services\Inventory\InventoryCountService;
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
            Section::make(__('admin.inventory.count.sections.count'))
                ->columns(3)
                ->schema([
                    TextEntry::make('count_number')->label(__('admin.inventory.count.fields.count')),
                    TextEntry::make('warehouse.name')->label(__('admin.inventory.count.fields.warehouse')),
                    TextEntry::make('scope_type')->label(__('admin.inventory.count.fields.scope'))->badge(),
                    TextEntry::make('status')->label(__('admin.inventory.count.fields.status'))->badge(),
                    TextEntry::make('is_partial')->label(__('admin.inventory.count.fields.partial'))->badge(),
                    TextEntry::make('materiality_threshold_minor')->label(__('admin.inventory.count.fields.variance_threshold'))->placeholder('—'),
                    TextEntry::make('counter.name')->label(__('admin.inventory.count.fields.counted_by'))->placeholder('—'),
                    TextEntry::make('confirmedBy.name')->label(__('admin.inventory.count.fields.confirmed_by'))->placeholder('—'),
                    TextEntry::make('opened_at')->dateTime()->placeholder('—'),
                    TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('adjustment.adjustment_number')->label(__('admin.inventory.count.fields.adjustment'))->placeholder('—'),
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
                        ->state(fn (InventoryCount $record): array => array_map(static function (array $line): array {
                            $line['value_display'] = $line['unvalued']
                                ? '—'
                                : number_format(((int) $line['variance_value_minor']) / 100, 2);

                            return $line;
                        }, app(InventoryCountService::class)->variance($record)))
                        ->schema([
                            TextEntry::make('product_variant_id')->label(__('admin.inventory.count.fields.variant')),
                            TextEntry::make('stock_condition')->badge(),
                            TextEntry::make('system_base_quantity')->label(__('admin.inventory.count.fields.system'))->numeric(decimalPlaces: 6),
                            TextEntry::make('counted_base_quantity')->label(__('admin.inventory.count.fields.counted'))->numeric(decimalPlaces: 6)->placeholder('—'),
                            TextEntry::make('variance_base_quantity')->label(__('admin.inventory.count.fields.variance'))->numeric(decimalPlaces: 6)->placeholder('—'),
                            TextEntry::make('value_display')->label(__('admin.inventory.count.fields.value')),
                            TextEntry::make('recount_requested')->label(__('admin.inventory.count.fields.flagged'))->badge(),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}
