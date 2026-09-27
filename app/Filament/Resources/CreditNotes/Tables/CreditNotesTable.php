<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Enums\CreditNoteReason;
use App\Enums\CreditNoteStatus;
use App\Enums\CreditNoteStockConsequence;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issue_date', 'desc')
            ->searchPlaceholder(__('admin.sales.credit_note_ui.search_placeholder'))
            ->columns([
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
            ->filters([
                SelectFilter::make('status')->options(array_combine(
                    array_map(fn (CreditNoteStatus $status): string => $status->value, CreditNoteStatus::cases()),
                    array_map(fn (CreditNoteStatus $status): string => $status->label(), CreditNoteStatus::cases()),
                )),
                SelectFilter::make('reason_category')->options(collect(CreditNoteReason::cases())
                    ->mapWithKeys(fn (CreditNoteReason $reason): array => [$reason->value => $reason->label()])
                    ->all()),
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                Filter::make('issue_date_between')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.sales.credit_note_ui.issued_from')),
                        DatePicker::make('until')->label(__('admin.sales.credit_note_ui.issued_until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('issue_date', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('issue_date', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (CreditNote $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([]);
    }

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
