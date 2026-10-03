<?php

declare(strict_types=1);

namespace App\Filament\Resources\Bills\Tables;

use App\Enums\BillStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Bills\Schemas\BillInfolist;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\Bill;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Filament\QueryBuilder\Constraints\NumberConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\QueryBuilder\Constraints\SelectConstraint;
use Filament\QueryBuilder\Constraints\TextConstraint;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

final class BillsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('bill_date', 'desc')
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('bill_number')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Bill $record): ?string => $record->isDraft() ? BillInfolist::blocker($record) : null),
                TextColumn::make('resolvedSupplier.name')->label(__('Supplier'))->searchable()->sortable(),
                TextColumn::make('supplier_reference')
                    ->label(__('Supplier reference'))
                    ->searchable(),
                TextColumn::make('supplier_reference_source')
                    ->label(__('Reference evidence'))
                    ->state(fn (Bill $record): string => $record->supplier_reference_backfilled_at === null
                        ? 'Supplier provided'
                        : 'Backfilled reference')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Backfilled reference' ? 'warning' : 'success'),
                TextColumn::make('purchaseOrder.purchase_order_number')->label(__('Purchase order'))->searchable(),
                TextColumn::make('description')->searchable()->limit(40),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->money()->sortable(),
                TextColumn::make('amount_paid')->money()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (BillStatus $state): string => $state->label())
                    ->color(fn (BillStatus $state): string => $state->color())
                    ->sortable(),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (Bill $record): string => $record->status->label()),
                Group::make('resolvedSupplier.name')->label(__('Supplier')),
                Group::make('bill_date')->label(__('Bill date'))->date(),
                Group::make('due_date')->label(__('Due date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => self::statusOptions())
                        ->multiple(),
                    TextConstraint::make('bill_number')->label(__('Bill number')),
                    TextConstraint::make('supplier_reference')->label(__('Supplier reference')),
                    RelationshipConstraint::make('resolvedSupplier')
                        ->label(__('Supplier'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()),
                    RelationshipConstraint::make('purchaseOrder')
                        ->label(__('Purchase order'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('purchase_order_number')->searchable()->multiple()),
                    NumberConstraint::make('total_amount')->label(__('Total amount')),
                    NumberConstraint::make('amount_paid')->label(__('Amount paid')),
                    DateConstraint::make('bill_date')->label(__('Bill date')),
                    DateConstraint::make('due_date')->label(__('Due date')),
                ]),
            ])
            ->recordActions([
                BillResource::approveAction()
                    ->label(__('Approve bill'))
                    ->icon(Heroicon::CheckCircle)
                    ->button()
                    ->color('primary')
                    ->visible(fn (Bill $record): bool => $record->isDraft() && BillInfolist::blocker($record) === null),
                Action::make('reviewBill')
                    ->label(__('Review bill'))
                    ->icon(Heroicon::MagnifyingGlass)
                    ->button()
                    ->color('warning')
                    ->visible(fn (Bill $record): bool => $record->isDraft()
                        && BillInfolist::blocker($record) !== null
                        && BillResource::canView($record))
                    ->url(fn (Bill $record): string => BillResource::getUrl('view', ['record' => $record])),
                BillResource::recordSupplierPaymentAction()->button(),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    BillResource::cancelAction(),
                    DeleteAction::make(),
                ])->icon(Heroicon::EllipsisVertical),
            ]);
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (BillStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }
}
