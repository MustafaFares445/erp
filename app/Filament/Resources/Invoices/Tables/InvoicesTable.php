<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            ->searchPlaceholder('Search by invoice number or customer name')
            ->columns([
                TextColumn::make('invoice_number')->searchable()->sortable(),
                TextColumn::make('customer.company_name')->label(__('admin.sales.fields.customer'))->searchable(),
                TextColumn::make('invoice_date')->date()->sortable(),
                TextColumn::make('due_date')->date()->sortable(),
                TextColumn::make('total_amount')->money()->sortable()->summarize(Sum::make()->money()->label('Total')),
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
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
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
                        true: fn (Builder $query): Builder => $query->whereNotNull('received_confirmation_type'),
                        false: fn (Builder $query): Builder => $query->whereNull('received_confirmation_type'),
                    ),
                Filter::make('issue_date_between')
                    ->schema([
                        DatePicker::make('from')->label('Issued from'),
                        DatePicker::make('until')->label('Issued until'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('invoice_date', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('invoice_date', '<=', $date))),
                Filter::make('due_date_between')
                    ->schema([
                        DatePicker::make('from')->label('Due from'),
                        DatePicker::make('until')->label('Due until'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('due_date', '<=', $date))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                InvoiceActions::recordPayment(),
                EditAction::make()->visible(fn (Invoice $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([]);
    }

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
