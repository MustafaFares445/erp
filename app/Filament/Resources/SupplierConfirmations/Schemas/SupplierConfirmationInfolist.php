<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierConfirmations\Schemas;

use App\Models\SupplierConfirmation;
use App\Support\QuantityFormatter;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SupplierConfirmationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Supplier commitment')
                ->description('Append-only supplier evidence for the outstanding Purchase Order quantity.')
                ->columns(4)
                ->schema([
                    TextEntry::make('purchaseOrder.purchase_order_number')->label('Purchase Order'),
                    TextEntry::make('supplier.name')->label('Supplier'),
                    TextEntry::make('confirmation_status')->label('Response')->badge(),
                    TextEntry::make('promised_at')->label('Promised date')->date()->placeholder('—'),
                    TextEntry::make('requested_total')
                        ->label('Requested')
                        ->state(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('requested_base_quantity'))),
                    TextEntry::make('confirmed_total')
                        ->label('Confirmed')
                        ->state(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('confirmed_base_quantity'))),
                    TextEntry::make('backordered_total')
                        ->label('Backordered')
                        ->state(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('backordered_base_quantity')))
                        ->badge(),
                    TextEntry::make('confirmedBy.name')->label('Recorded by')->placeholder('—'),
                    TextEntry::make('notes')->label('Response note')->columnSpanFull()->placeholder('—')->wrap(),
                ]),
            Section::make('Line commitments')
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->columns(7)
                        ->schema([
                            TextEntry::make('productVariant.product.name')->label('Product'),
                            TextEntry::make('productVariant.sku')->label('SKU'),
                            TextEntry::make('purchaseOrderLine.supplier_item_number')->label('Supplier item')->placeholder('—'),
                            TextEntry::make('requested_base_quantity')
                                ->label('Requested')
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            TextEntry::make('confirmed_base_quantity')
                                ->label('Confirmed')
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state ?? '0')),
                            TextEntry::make('backordered_base_quantity')
                                ->label('Backordered')
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state ?? '0')),
                            TextEntry::make('confirmation_status')->label('Status')->badge(),
                        ]),
                ]),
        ]);
    }
}
