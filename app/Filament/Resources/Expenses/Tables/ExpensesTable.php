<?php

declare(strict_types=1);

namespace App\Filament\Resources\Expenses\Tables;

use App\Enums\ExpenseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Expense;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('expense_date', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('expense_number')->searchable()->sortable(),
                TextColumn::make('merchant_name')->label(__('Merchant'))->searchable(),
                TextColumn::make('supplier.name')->searchable(),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('total_amount')->money()->sortable(),
                TextColumn::make('amount_paid')->money()->sortable(),
                TextColumn::make('payment_date')->date()->sortable()->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ExpenseStatus $state): string => $state->label())
                    ->color(fn (ExpenseStatus $state): string => $state->color())
                    ->sortable(),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (Expense $record): string => $record->status->label()),
                Group::make('supplier.name')->label(__('Supplier')),
                Group::make('expense_date')->label(__('Expense date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => self::statusOptions())
                        ->multiple(),
                    TextConstraint::make('expense_number')->label(__('Expense number')),
                    TextConstraint::make('merchant_name')->label(__('Merchant')),
                    RelationshipConstraint::make('supplier')
                        ->label(__('Supplier'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    NumberConstraint::make('total_amount')->label(__('Total amount')),
                    NumberConstraint::make('amount_paid')->label(__('Amount paid')),
                    DateConstraint::make('expense_date')->label(__('Expense date')),
                    DateConstraint::make('due_date')->label(__('Due date')),
                    DateConstraint::make('payment_date')->label(__('Payment date')),
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                ExpenseResource::approveAction(),
                ExpenseResource::payAction(),
                ExpenseResource::cancelAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (ExpenseStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
