<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('payment_date', 'desc')
            ->columns([
                TextColumn::make('payment_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('paymentMethod.name')->label(__('admin.sales.fields.payment_method')),
                TextColumn::make('payment_date')->date()->sortable(),
                TextColumn::make('amount')->money()->sortable(),
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Payment $record): bool => ! $record->isPosted()),
            ])
            ->toolbarActions([]);
    }
}
