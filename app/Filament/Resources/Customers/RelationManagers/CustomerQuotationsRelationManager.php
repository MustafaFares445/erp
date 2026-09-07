<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only (WP-3.1, GAP-UI-03, CR-05) — editing a quotation from inside the
 * customer record is how lifecycle guards get bypassed; the link out is the
 * only action.
 */
final class CustomerQuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotations';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quotation_number')->label('Quotation'),
                TextColumn::make('issue_date')->date(),
                TextColumn::make('status')->badge(),
                TextColumn::make('grand_total')->alignEnd(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->recordUrl(fn (Quotation $record): string => QuotationResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
