<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Tables;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\PaymentTransactions\Actions\PaymentTransactionActions;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PaymentTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('checkout_session_id')->label('Session')->limit(20)->placeholder('—'),
                TextColumn::make('customer.company_name')->label('Customer')->searchable(),
                TextColumn::make('purpose_type')
                    ->label('Purpose')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),
                TextColumn::make('amount')->label('Amount')->state(fn (PaymentTransaction $record): float => $record->amount())->money(fn (PaymentTransaction $record): string => $record->currency),
                TextColumn::make('status')
                    ->label('Provider status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentTransactionStatus $state): string => $state->label())
                    ->color(fn (PaymentTransactionStatus $state): string => $state->color()),
                IconColumn::make('settled')
                    ->label('ERP settled')
                    ->state(fn (PaymentTransaction $record): bool => $record->isSettled())
                    ->boolean(),
                TextColumn::make('created_at')->label('Created')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Provider status')
                    ->options(fn (): array => array_combine(
                        array_map(fn (PaymentTransactionStatus $status): string => $status->value, PaymentTransactionStatus::cases()),
                        array_map(fn (PaymentTransactionStatus $status): string => $status->label(), PaymentTransactionStatus::cases()),
                    )),
                Filter::make('purpose_type')
                    ->schema([
                        Select::make('value')
                            ->label('Purpose')
                            ->options([
                                Order::class => 'Order',
                                Invoice::class => 'Invoice',
                                TicketPaymentLink::class => 'Ticket payment',
                            ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $inner): Builder => $inner->where('purpose_type', $data['value']),
                    )),
                Filter::make('mismatch')
                    ->label('Succeeded but not yet settled')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', PaymentTransactionStatus::Succeeded->value)
                        ->where(function (Builder $query): void {
                            $query->where(function (Builder $query): void {
                                $query->where('purpose_type', TicketPaymentLink::class)
                                    ->whereHasMorph('purpose', [TicketPaymentLink::class], fn (Builder $query): Builder => $query
                                        ->where('status', '!=', PaymentLinkStatus::Settled->value));
                            })->orWhere(function (Builder $query): void {
                                $query->where('purpose_type', '!=', TicketPaymentLink::class)
                                    ->whereNull('payment_id');
                            });
                        })),
            ])
            ->recordActions([
                ViewAction::make(),
                PaymentTransactionActions::refreshStatus(),
                PaymentTransactionActions::retrySettlement(),
            ])
            ->toolbarActions([]);
    }
}
