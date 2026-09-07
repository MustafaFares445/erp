<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only (WP-3.1, GAP-UI-03, CR-05) — the link out is the only action.
 */
final class CustomerInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')->label('Invoice'),
                TextColumn::make('invoice_date')->date(),
                TextColumn::make('status')->badge(),
                TextColumn::make('total_amount')->alignEnd(),
                TextColumn::make('amount_paid')->alignEnd(),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->recordUrl(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
