<?php

declare(strict_types=1);

namespace App\Filament\Resources\SerializedInventoryUnits\Schemas;

use App\Models\SerializedInventoryUnit;
use App\Services\Inventory\SerializedInventoryTimelineService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SerializedInventoryUnitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->columns(2)->schema([
                    TextEntry::make('serial_number')->label('Serial'),
                    TextEntry::make('iot_number')->label('IoT')->placeholder('—'),
                    TextEntry::make('productVariant.sku')->label('SKU'),
                    TextEntry::make('productVariant.product.name')->label('Product'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('warehouse.code')->label('Current warehouse')->placeholder('—'),
                    TextEntry::make('receiptItem.receipt.receipt_number')->label('Receipt')->placeholder('—'),
                ]),
                Section::make('Movement history')->schema([
                    RepeatableEntry::make('timeline')
                        ->state(fn (SerializedInventoryUnit $record): array => app(SerializedInventoryTimelineService::class)->events($record))
                        ->schema([
                            TextEntry::make('occurred_at')->label('Date')->dateTime(),
                            TextEntry::make('type')->badge(),
                            TextEntry::make('warehouse')->placeholder('—'),
                            TextEntry::make('quantity')->numeric(decimalPlaces: 3),
                            TextEntry::make('source')->placeholder('—'),
                            TextEntry::make('notes')->placeholder('—'),
                        ])
                        ->columns(3),
                ]),
            ]);
    }
}
