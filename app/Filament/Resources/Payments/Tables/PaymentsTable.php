<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Models\CustomerProfile;
use App\Models\Payment;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('payment_date', 'desc')
            ->searchPlaceholder(__('admin.sales.payment_ui.search_placeholder'))
            ->columns([
                TextColumn::make('payment_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('source')
                    ->label(__('admin.sales.payment_ui.source'))
                    ->state(fn (Payment $record): string => $record->providerTransaction?->provider->label() ?? __('admin.sales.payment_ui.manual')),
                TextColumn::make('amount')
                    ->label(__('admin.sales.payment_ui.received'))
                    ->money(static fn (Payment $record): string => $record->currency)
                    ->sortable(),
                TextColumn::make('applied_amount')
                    ->label(__('admin.sales.payment_ui.applied_amount'))
                    ->state(fn (Payment $record): float => $record->allocatedAmountMinor() / 100)
                    ->money(static fn (Payment $record): string => $record->currency),
                TextColumn::make('customer_deposit')
                    ->label(__('admin.sales.payment_ui.customer_deposit'))
                    ->state(fn (Payment $record): float => $record->customerDepositMinor() / 100)
                    ->money(static fn (Payment $record): string => $record->currency),
                TextColumn::make('allocations_count')
                    ->label(__('admin.sales.payment_ui.related_invoices'))
                    ->formatStateUsing(static fn (int $state): string => trans_choice('admin.sales.payment_ui.invoice_count', $state, ['count' => $state])),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color())
                    ->description(fn (PaymentStatus $state): string => $state->description())
                    ->sortable(),
                TextColumn::make('payment_date')->label(__('admin.sales.fields.date'))->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(PaymentStatus::cases())
                        ->mapWithKeys(fn (PaymentStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                SelectFilter::make('currency')
                    ->label(__('admin.sales.fields.currency'))
                    ->options(fn (): array => Payment::query()->distinct()->orderBy('currency')->pluck('currency', 'currency')->all()),
                Filter::make('payment_date_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Paid from')),
                        DatePicker::make('until')->label(__('Paid until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('payment_date', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('payment_date', '<=', $date))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Payment $record): bool => ! $record->isPosted()),
            ])
            ->toolbarActions([]);
    }

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
