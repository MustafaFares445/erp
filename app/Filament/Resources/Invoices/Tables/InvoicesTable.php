<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            ->columns([
                TextColumn::make('invoice_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('invoice_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->money()->sortable(),
                TextColumn::make('amount_paid')->money()->sortable(),
                TextColumn::make('credited_amount')->money()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InvoiceStatus $state): string => $state->label())
                    ->color(fn (InvoiceStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('received_confirmation_type')
                    ->label('Receipt confirmation')
                    ->badge()
                    ->formatStateUsing(fn (?InvoiceConfirmationType $state): ?string => $state?->label())
                    ->placeholder('Not confirmed'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])
                        ->all(),
                ),
                SelectFilter::make('received_confirmation_type')
                    ->label('Receipt confirmation type')
                    ->options(
                        collect(InvoiceConfirmationType::cases())
                            ->mapWithKeys(fn (InvoiceConfirmationType $type): array => [$type->value => $type->label()])
                            ->all(),
                    ),
                TernaryFilter::make('receipt_confirmed')
                    ->label('Receipt confirmed')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('received_confirmation_type'),
                        false: fn ($query) => $query->whereNull('received_confirmation_type'),
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (Invoice $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([]);
    }
}
