<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentTransactions\Tables;

use App\Enums\PaymentProvider;
use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PaymentTransactions\Actions\PaymentTransactionActions;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\TicketPaymentLink;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
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
                TextColumn::make('checkout_session_id')
                    ->label(__('admin.payments.transaction_ui.transaction'))
                    ->state(fn (PaymentTransaction $record): ?string => $record->checkout_session_id ?? $record->payment_intent_id)
                    ->limit(20)
                    ->placeholder(__('—')),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('purpose_type')
                    ->label(__('admin.payments.transaction_ui.purpose'))
                    ->state(fn (PaymentTransaction $record): string => $record->purposeLabel()),
                TextColumn::make('amount')->label(__('admin.payments.transaction_ui.amount'))->state(fn (PaymentTransaction $record): float => $record->amount())->money(fn (PaymentTransaction $record): string => $record->currency),
                TextColumn::make('provider')
                    ->label(__('admin.payments.transaction_ui.provider'))
                    ->formatStateUsing(fn (?PaymentProvider $state): string => $state?->label() ?? '—'),
                TextColumn::make('status')
                    ->label(__('admin.payments.transaction_ui.provider_status'))
                    ->badge()
                    ->formatStateUsing(fn (PaymentTransactionStatus $state): string => $state->label())
                    ->color(fn (PaymentTransactionStatus $state): string => $state->color()),
                TextColumn::make('settlement_state')
                    ->label(__('admin.payments.transaction_ui.erp_settlement'))
                    ->state(fn (PaymentTransaction $record): string => $record->settlementState()->label())
                    ->badge()
                    ->color(fn (PaymentTransaction $record): string => $record->settlementState()->color())
                    ->description(fn (PaymentTransaction $record): string => $record->settlementDescription()),
                TextColumn::make('payment.payment_number')
                    ->label(__('admin.payments.transaction_ui.erp_payment'))
                    ->placeholder(fn (PaymentTransaction $record): string => $record->purpose instanceof TicketPaymentLink
                        ? $record->settlementDescription()
                        : ($record->payment_id !== null
                            ? __('admin.payments.transaction_ui.payment_record_missing')
                            : __('admin.payments.transaction_ui.payment_not_created')))
                    ->url(fn (PaymentTransaction $record): ?string => $record->payment instanceof Payment
                        ? PaymentResource::getUrl('view', ['record' => $record->payment_id])
                        : null),
                TextColumn::make('created_at')->label(__('admin.payments.transaction_ui.created_at'))->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.payments.transaction_ui.provider_status'))
                    ->options(fn (): array => array_combine(
                        array_map(fn (PaymentTransactionStatus $status): string => $status->value, PaymentTransactionStatus::cases()),
                        array_map(fn (PaymentTransactionStatus $status): string => $status->label(), PaymentTransactionStatus::cases()),
                    )),
                Filter::make('purpose_type')
                    ->schema([
                        Select::make('value')
                            ->label(__('admin.payments.transaction_ui.purpose'))
                            ->options([
                                Order::class => __('admin.sales.payment_ui.order_label'),
                                Invoice::class => __('admin.sales.payment_ui.invoice_label'),
                                TicketPaymentLink::class => __('admin.sales.payment_ui.ticket_label'),
                            ]),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $inner): Builder => $inner->where('purpose_type', $data['value']),
                    )),
                Filter::make('mismatch')
                    ->label(__('admin.payments.transaction_tabs.requires_attention'))
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        'payment_transactions.id',
                        PaymentTransaction::query()->requiresSettlementAttention()->select('payment_transactions.id'),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                PaymentTransactionActions::refreshStatus(),
                PaymentTransactionActions::retrySettlement(),
            ])
            ->toolbarActions([]);
    }
}
