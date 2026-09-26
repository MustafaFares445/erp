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
            ->searchPlaceholder('Search by payment number or customer name')
            ->columns([
                TextColumn::make('payment_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('paymentMethod.name')->label(__('admin.sales.fields.payment_method')),
                TextColumn::make('payment_date')->date()->sortable(),
                TextColumn::make('currency')->label('Currency')->sortable(),
                TextColumn::make('amount')
                    ->money(static fn (Payment $record): string => $record->currency)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('posted_at')->dateTime()->placeholder('—'),
                TextColumn::make('reversed_at')->dateTime()->placeholder('—'),
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
                    ->label('Currency')
                    ->options(fn (): array => Payment::query()->distinct()->orderBy('currency')->pluck('currency', 'currency')->all()),
                Filter::make('payment_date_between')
                    ->schema([
                        DatePicker::make('from')->label('Paid from'),
                        DatePicker::make('until')->label('Paid until'),
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
