<?php

declare(strict_types=1);

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Enums\CreditNoteStatus;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
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
            ->searchPlaceholder('Search by credit note number, customer name, or invoice number')
            ->columns([
                TextColumn::make('credit_note_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('invoice.invoice_number')->label(__('admin.sales.fields.invoice_number'))->searchable(),
                TextColumn::make('issue_date')->date()->sortable(),
                TextColumn::make('grand_total')->money()->sortable()->summarize(Sum::make()->money()->label('Total')),
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
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                Filter::make('issue_date_between')
                    ->schema([
                        DatePicker::make('from')->label('Issued from'),
                        DatePicker::make('until')->label('Issued until'),
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
