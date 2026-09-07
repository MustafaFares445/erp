<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only (WP-3.1, GAP-UI-03, CR-05) — the link out is the only action.
 */
final class CustomerPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('payment_number')->label('Payment'),
                TextColumn::make('payment_date')->date(),
                TextColumn::make('status')->badge(),
                TextColumn::make('amount')->alignEnd(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->recordUrl(fn (Payment $record): string => PaymentResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
