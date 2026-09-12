<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierDebitNotes;

use App\Filament\Resources\SupplierDebitNotes\Pages\ListSupplierDebitNotes;
use App\Filament\Resources\SupplierDebitNotes\Pages\ViewSupplierDebitNote;
use App\Models\SupplierDebitNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

final class SupplierDebitNoteResource extends Resource
{
    protected static ?string $model = SupplierDebitNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMinus;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.accounting';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Supplier debit notes';
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', SupplierDebitNote::class) ?? false;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('debit_note_number')->label('Debit note')->searchable()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable()->sortable(),
                TextColumn::make('inventoryReturn.return_number')->label('Supplier return')->searchable(),
                TextColumn::make('bill.bill_number')->label('Bill')->searchable(),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('subtotal')->money()->sortable(),
                TextColumn::make('tax_total')->money()->sortable(),
                TextColumn::make('total_amount')->money()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'draft' => 'Draft',
                    'confirmed' => 'Confirmed',
                    'reversed' => 'Reversed',
                ]),
            ])
            ->recordUrl(fn (SupplierDebitNote $record): string => self::getUrl('view', ['record' => $record]));
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSupplierDebitNotes::route('/'),
            'view' => ViewSupplierDebitNote::route('/{record}'),
        ];
    }
}
