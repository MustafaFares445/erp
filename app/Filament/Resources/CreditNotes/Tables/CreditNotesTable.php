<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CreditNoteStockConsequence;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Tables\Columns\FavoriteColumn;
use App\Filament\Tables\Filters\TableQueryBuilder;
use App\Models\CreditNote;
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

final class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->searchPlaceholder(__('admin.sales.credit_note_ui.search_placeholder'))
            ->columns([
                FavoriteColumn::make(),
                TextColumn::make('credit_note_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('invoice.invoice_number')
                    ->label(__('admin.sales.credit_note_ui.source_invoice'))
                    ->searchable()
                    ->url(fn (CreditNote $record): ?string => $record->invoice_id === null
                        ? null
                        : InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
                TextColumn::make('reason_category')
                    ->label(__('admin.sales.fields.reason_category'))
                    ->formatStateUsing(fn (CreditNoteReason $state): string => $state->label()),
                TextColumn::make('grand_total')
                    ->label(__('admin.sales.credit_note_ui.total_credit'))
                    ->money()
                    ->sortable(),
                TextColumn::make('stock_consequence')
                    ->label(__('admin.sales.credit_note_ui.stock_effect'))
                    ->formatStateUsing(fn (CreditNoteStockConsequence $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(static fn (CreditNoteStatus $state): string => $state->label())
                    ->color(static fn (CreditNoteStatus $state): string => match ($state) {
                        CreditNoteStatus::Draft, CreditNoteStatus::Cancelled => 'gray',
                        CreditNoteStatus::Confirmed => 'success',
                        CreditNoteStatus::Reversed => 'warning',
                    }),
                TextColumn::make('issue_date')->label(__('admin.sales.fields.date'))->date()->sortable(),
            ])
            ->groups([
                Group::make('status')
                    ->label(__('Status'))
                    ->getTitleFromRecordUsing(static fn (CreditNote $record): string => $record->status->label()),
                Group::make('reason_category')
                    ->label(__('admin.sales.fields.reason_category'))
                    ->getTitleFromRecordUsing(static fn (CreditNote $record): string => $record->reason_category->label()),
                Group::make('customer.company_name')->label(__('admin.sales.fields.customer')),
                Group::make('issue_date')->label(__('admin.sales.fields.date'))->date(),
            ])
            ->filters([
                TableQueryBuilder::make([
                    SelectConstraint::make('status')
                        ->label(__('Status'))
                        ->options(static fn (): array => collect(CreditNoteStatus::cases())
                            ->mapWithKeys(static fn (CreditNoteStatus $status): array => [$status->value => $status->label()])
                            ->all())
                        ->multiple(),
                    SelectConstraint::make('reason_category')
                        ->label(__('admin.sales.fields.reason_category'))
                        ->options(static fn (): array => collect(CreditNoteReason::cases())
                            ->mapWithKeys(static fn (CreditNoteReason $reason): array => [$reason->value => $reason->label()])
                            ->all())
                        ->multiple(),
                    TextConstraint::make('credit_note_number')->label(__('Reference')),
                    RelationshipConstraint::make('customer')
                        ->label(__('admin.sales.fields.customer'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('company_name')->searchable()->multiple()),
                    RelationshipConstraint::make('invoice')
                        ->label(__('admin.sales.credit_note_ui.source_invoice'))
                        ->selectable(IsRelatedToOperator::make()->titleAttribute('invoice_number')->searchable()->multiple()),
                    NumberConstraint::make('grand_total')->label(__('admin.sales.credit_note_ui.total_credit')),
                    DateConstraint::make('issue_date')->label(__('admin.sales.fields.date')),
                    DateConstraint::make('confirmed_at')->label(__('Confirmed at')),
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (CreditNote $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([]);
    }
}
