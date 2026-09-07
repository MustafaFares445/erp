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
            Section::make('Count')
                ->columns(3)
                ->schema([
                    TextEntry::make('count_number')->label('Count'),
                    TextEntry::make('warehouse.name')->label('Warehouse'),
                    TextEntry::make('scope_type')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('is_partial')->label('Partial')->badge(),
                    TextEntry::make('materiality_threshold_minor')->label('Materiality threshold')->placeholder('—'),
                    TextEntry::make('counter.name')->label('Counted by')->placeholder('—'),
                    TextEntry::make('confirmedBy.name')->label('Confirmed by')->placeholder('—'),
                    TextEntry::make('opened_at')->dateTime()->placeholder('—'),
                    TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('adjustment.adjustment_number')->label('Adjustment')->placeholder('—'),
                ]),
            Section::make('Variance worksheet')
                ->description('Materiality-flagged exceptions are listed first.')
                ->schema([
                    RepeatableEntry::make('variance')
                        ->hiddenLabel()
                        ->state(fn (InventoryCount $record): array => array_map(static function (array $line): array {
                            $line['value_display'] = $line['unvalued']
                                ? 'Unvalued'
                                : number_format(((int) $line['variance_value_minor']) / 100, 2);

                            return $line;
                        }, app(InventoryCountService::class)->variance($record)))
                        ->schema([
                            TextEntry::make('product_variant_id')->label('Variant #'),
                            TextEntry::make('stock_condition')->badge(),
                            TextEntry::make('system_base_quantity')->label('System')->numeric(decimalPlaces: 6),
                            TextEntry::make('counted_base_quantity')->label('Counted')->numeric(decimalPlaces: 6)->placeholder('Uncounted'),
                            TextEntry::make('variance_base_quantity')->label('Variance')->numeric(decimalPlaces: 6)->placeholder('—'),
                            TextEntry::make('value_display')->label('Value'),
                            TextEntry::make('recount_requested')->label('Flagged')->badge(),
                        ])
                        ->columns(4),
                ]),
        ]);
    }
}
