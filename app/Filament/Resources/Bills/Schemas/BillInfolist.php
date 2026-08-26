<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Schemas;

use App\Models\BillLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class BillInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Bill details')->columns(3)->schema([
                TextEntry::make('bill_number')->label('Bill number'),
                TextEntry::make('status')->label('Status')->badge(),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('supplier_reference')->label('Supplier reference')->placeholder('Not provided'),
                TextEntry::make('purchaseOrder.order_number')->label('Purchase order')->placeholder('Not linked'),
                TextEntry::make('paymentTerm.name')->label('Payment term')->placeholder('Not provided'),
                TextEntry::make('bill_date')->label('Bill date')->date(),
                TextEntry::make('due_date')->label('Due date')->date()->placeholder('Not provided'),
                TextEntry::make('subtotal')->label('Subtotal')->numeric(decimalPlaces: 2),
                TextEntry::make('tax_total')->label('Input tax')->numeric(decimalPlaces: 2),
                TextEntry::make('grand_total')->label('Grand total')->numeric(decimalPlaces: 2),
                TextEntry::make('paid_amount')->label('Paid amount')->numeric(decimalPlaces: 2),
                TextEntry::make('description')->label('Description')->columnSpanFull(),
            ]),
            Section::make('Lines and three-way match')->schema([
                RepeatableEntry::make('lines')->label('')->columns(8)->schema([
                    TextEntry::make('description')->label('Description'),
                    TextEntry::make('quantity')->label('Billed quantity')->numeric(decimalPlaces: 3),
                    TextEntry::make('unit_price')->label('Billed unit price')->numeric(decimalPlaces: 2),
                    TextEntry::make('ordered_quantity')
                        ->label('Ordered quantity')
                        ->state(static fn (BillLine $record): string => self::orderedQuantity($record)),
                    TextEntry::make('received_quantity')
                        ->label('Received quantity')
                        ->state(static fn (BillLine $record): string => number_format($record->receivedQuantity(), 3, '.', '')),
                    TextEntry::make('cumulative_billed_quantity')
                        ->label('Cumulative billed')
                        ->state(static fn (BillLine $record): string => number_format($record->cumulativeBilledQuantity(), 3, '.', '')),
                    TextEntry::make('quantity_variance')
                        ->label('Quantity variance')
                        ->badge()
                        ->state(static fn (BillLine $record): string => $record->hasQuantityVariance() ? 'Variance' : 'Matched'),
                    TextEntry::make('price_variance')
                        ->label('Unit-price variance')
                        ->badge()
                        ->state(static fn (BillLine $record): string => $record->hasUnitPriceVariance() ? 'Variance' : 'Matched'),
                ]),
            ]),
        ]);
    }

    private static function orderedQuantity(BillLine $line): string
    {
        $purchaseOrderLine = $line->purchaseOrderLine;

        return $purchaseOrderLine === null
            ? 'Not matched'
            : number_format((float) $purchaseOrderLine->quantity_ordered, 3, '.', '');
    }
}
