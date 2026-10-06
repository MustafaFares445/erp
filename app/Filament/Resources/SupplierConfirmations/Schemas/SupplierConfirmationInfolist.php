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
            Section::make(__('Supplier commitment'))
                ->description(__('Append-only supplier evidence for the outstanding Purchase Order quantity.'))
                ->columns(4)
                ->schema([
                    TextEntry::make('purchaseOrder.purchase_order_number')->label(__('Purchase Order')),
                    TextEntry::make('supplier.name')->label(__('Supplier')),
                    TextEntry::make('confirmation_status')->label(__('Response'))->badge(),
                    TextEntry::make('supplier_reference')->label(__('Supplier reference'))->placeholder(__('—')),
                    TextEntry::make('promised_at')->label(__('Promised date'))->date()->placeholder(__('—')),
                    TextEntry::make('requested_total')
                        ->label(__('Requested'))
                        ->state(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('requested_base_quantity'))),
                    TextEntry::make('confirmed_total')
                        ->label(__('Confirmed'))
                        ->state(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('confirmed_base_quantity'))),
                    TextEntry::make('backordered_total')
                        ->label(__('Backordered'))
                        ->state(fn (SupplierConfirmation $record): string => QuantityFormatter::display($record->items->sum('backordered_base_quantity')))
                        ->badge(),
                    TextEntry::make('confirmedBy.name')->label(__('Recorded by'))->placeholder(__('—')),
                    TextEntry::make('notes')->label(__('Response note'))->columnSpanFull()->placeholder(__('—'))->wrap(),
                ]),
            Section::make(__('Line commitments'))
                ->schema([
                    RepeatableEntry::make('items')
                        ->label('')
                        ->columns(8)
                        ->schema([
                            TextEntry::make('productVariant.product.name')->label(__('Product')),
                            TextEntry::make('productVariant.sku')->label(__('SKU')),
                            TextEntry::make('purchaseOrderLine.supplier_item_number')->label(__('Supplier item'))->placeholder(__('—')),
                            TextEntry::make('requested_base_quantity')
                                ->label(__('Requested'))
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state)),
                            TextEntry::make('confirmed_base_quantity')
                                ->label(__('Confirmed'))
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state ?? '0')),
                            TextEntry::make('backordered_base_quantity')
                                ->label(__('Backordered'))
                                ->formatStateUsing(static fn (mixed $state): string => QuantityFormatter::display($state ?? '0')),
                            TextEntry::make('promised_at')->label(__('Promised date'))->date()->placeholder(__('—')),
                            TextEntry::make('confirmation_status')->label(__('Status'))->badge(),
                        ]),
                ]),
        ]);
    }
}
