<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use App\Models\PaymentTransaction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only, same reasoning as {@see CustomerPaymentsRelationManager} — the
 * full Payment Transactions resource is where reconciliation actions live.
 */
final class CustomerPaymentTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentTransactions';

    protected static ?string $title = 'Payment Transactions';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('purpose_type')->label('Purpose')->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('amount')->label('Amount')->state(fn (PaymentTransaction $record): float => $record->amount())->money(fn (PaymentTransaction $record): string => $record->currency),
                TextColumn::make('status')
                    ->label('Provider status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentTransactionStatus $state): string => $state->label())
                    ->color(fn (PaymentTransactionStatus $state): string => $state->color()),
                IconColumn::make('settled')->label('ERP settled')->state(fn (PaymentTransaction $record): bool => $record->isSettled())->boolean(),
                TextColumn::make('created_at')->label('Created')->dateTime(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (PaymentTransaction $record): string => PaymentTransactionResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
