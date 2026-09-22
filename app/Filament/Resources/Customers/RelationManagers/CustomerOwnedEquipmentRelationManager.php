<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only — the customer's serialized units currently in their custody
 * (warranty tracking only, no lifecycle action belongs here).
 */
final class CustomerOwnedEquipmentRelationManager extends RelationManager
{
    protected static string $relationship = 'ownedEquipment';

    protected static ?string $title = 'Owned Equipment & Warranty';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('serial_number')->label('Serial number'),
                TextColumn::make('productVariant.sku')->label('Product'),
                TextColumn::make('stock_condition')->badge(),
                TextColumn::make('warranty_started_on')->date()->placeholder('—'),
                TextColumn::make('warranty_expires_on')->label('Warranty expires')->date()->placeholder('—'),
            ])
            ->defaultSort('warranty_expires_on', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
