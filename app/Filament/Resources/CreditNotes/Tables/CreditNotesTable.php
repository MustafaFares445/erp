<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Enums\CreditNoteStatus;
use App\Models\CreditNote;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->columns([
                TextColumn::make('credit_note_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('invoice.invoice_number')->label(__('admin.sales.fields.invoice_number'))->searchable(),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('grand_total')->money()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(static fn (CreditNoteStatus $state): string => $state->label())
                    ->color(static fn (CreditNoteStatus $state): string => match ($state) {
                        CreditNoteStatus::Draft => 'gray',
                        CreditNoteStatus::Confirmed => 'success',
                        CreditNoteStatus::Reversed, CreditNoteStatus::Cancelled => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_combine(
                    array_map(fn (CreditNoteStatus $status): string => $status->value, CreditNoteStatus::cases()),
                    array_map(fn (CreditNoteStatus $status): string => $status->label(), CreditNoteStatus::cases()),
                )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (CreditNote $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([]);
    }
}
