<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceFinancialStatus;
use App\Enums\InvoiceNextStep;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Invoice;
use App\Services\Sales\InvoiceBalanceService;
use App\Services\Sales\InvoiceNextActionResolver;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use WeakMap;

final class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            ->searchPlaceholder(__('Search by invoice number or customer name'))
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('invoice_number')->label(__('Invoice'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('status')
                    ->label(__('Document status'))
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('financial_status')
                    ->label(__('Financial status'))
                    ->state(fn (Invoice $record): InvoiceFinancialStatus => app(InvoiceBalanceService::class)->financialStatus($record))
                    ->badge()
                    ->formatStateUsing(fn (InvoiceFinancialStatus $state): string => $state->label())
                    ->color(fn (InvoiceFinancialStatus $state): string => $state->color()),
                TextColumn::make('total_amount')->money()->sortable()->summarize(Sum::make()->money()->label(__('Total'))),
                TextColumn::make('outstanding')
                    ->label(__('Outstanding'))
                    ->state(fn (Invoice $record): float => $record->outstandingAmount())
                    ->money()
                    ->weight('bold')
                    ->color(fn (Invoice $record): string => $record->outstandingAmount() > 0.0 ? 'danger' : 'success')
                    ->description(fn (Invoice $record): ?string => self::outstandingBreakdown($record)),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('next_action')
                    ->label(__('Next action'))
                    ->state(fn (Invoice $record): string => app(InvoiceNextActionResolver::class)->resolve($record))
                    ->wrap(),
                TextColumn::make('invoice_date')->label(__('Invoice date'))->date()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('amount_paid')->label(__('Amount paid'))->money()->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')->label(__('Created by'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Document status'))
                    ->getTitleFromRecordUsing(static fn (Invoice $record): string => $record->status->label()),
                Group::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                Group::make('invoice_date')->label(__('Invoice date'))->date(),
                Group::make('due_date')->label(__('Due date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Document status'))
                        ->options(
                            collect(InvoiceStatus::cases())
                                ->mapWithKeys(static fn (InvoiceStatus $status): array => [$status->value => $status->label()])
                                ->all(),
                        )
                        ->multiple(),
                    TextConstraint::make('invoice_number')->label(__('Invoice')),
                    RelationshipConstraint::make('customer')
                        ->label(__('admin.sales.fields.customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    NumberConstraint::make('total_amount')->label(__('Total amount')),
                    NumberConstraint::make('amount_paid')->label(__('Amount paid')),
                    DateConstraint::make('invoice_date')->label(__('Invoice date')),
                    DateConstraint::make('due_date')->label(__('Due date')),
                ]),
                TrashedFilter::make(),
            ])
            ->recordActions([
                InvoiceActions::issue()
                    ->button()
                    ->hidden(fn (Invoice $record): bool => self::nextStep($record) !== InvoiceNextStep::Issue),
                InvoiceActions::retryDepositApplication()
                    ->button()
                    ->hidden(fn (Invoice $record): bool => self::nextStep($record) !== InvoiceNextStep::RetryDepositApplication),
                InvoiceActions::recordPayment()
                    ->button()
                    ->hidden(fn (Invoice $record): bool => self::nextStep($record) !== InvoiceNextStep::RecordPayment),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()->visible(fn (Invoice $record): bool => $record->isDraft()),
                ]),
            ])
            ->toolbarActions([]);
    }

    private static function nextStep(Invoice $record): ?InvoiceNextStep
    {
        /** @var WeakMap<Invoice, InvoiceNextStep|false>|null $cache */
        static $cache = null;

        $cache ??= new WeakMap;

        $cached = $cache[$record] ?? null;

        if ($cached instanceof InvoiceNextStep) {
            return $cached;
        }

        if ($cached === false) {
            return null;
        }

        $step = app(InvoiceNextActionResolver::class)->step($record);
        $cache[$record] = $step ?? false;

        return $step;
    }

    private static function outstandingBreakdown(Invoice $record): ?string
    {
        $parts = [];

        if ((float) $record->amount_paid > 0.0) {
            $parts[] = 'Paid: '.number_format((float) $record->amount_paid, 2);
        }

        if ((float) $record->credited_amount > 0.0) {
            $parts[] = 'Credited: '.number_format((float) $record->credited_amount, 2);
        }

        $writtenOff = $record->writtenOffAmountMinor() / 100;

        if ($writtenOff > 0.0) {
            $parts[] = 'Written off: '.number_format($writtenOff, 2);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
