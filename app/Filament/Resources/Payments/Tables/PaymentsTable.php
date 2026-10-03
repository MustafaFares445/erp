<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payments\Tables;

use App\Enums\PaymentStatus;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Payment;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('payment_date', 'desc')
            ->searchPlaceholder(__('admin.sales.payment_ui.search_placeholder'))
            ->columns([
                FavoriteColumn::make(),
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
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (Payment $record): string => $record->status->label()),
                Group::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                Group::make('currency')->label(__('admin.sales.fields.currency')),
                Group::make('payment_date')->label(__('admin.sales.fields.date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => collect(PaymentStatus::cases())
                            ->mapWithKeys(static fn (PaymentStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    TextConstraint::make('payment_number')->label(__('Reference')),
                    RelationshipConstraint::make('customer')
                        ->label(__('admin.sales.fields.customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    RelationshipConstraint::make('paymentMethod')
                        ->label(__('Payment method'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    NumberConstraint::make('amount')->label(__('admin.sales.payment_ui.received')),
                    TextConstraint::make('currency')->label(__('admin.sales.fields.currency')),
                    DateConstraint::make('payment_date')->label(__('admin.sales.fields.date')),
                    DateConstraint::make('posted_at')->label(__('Posted at')),
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Payment $record): bool => ! $record->isPosted()),
            ])
            ->toolbarActions([]);
    }
}
