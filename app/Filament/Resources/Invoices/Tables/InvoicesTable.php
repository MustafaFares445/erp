<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Enums\InvoiceFinancialStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Services\Sales\InvoiceBalanceService;
use App\Services\Sales\InvoiceNextActionResolver;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('invoice_date', 'desc')
            ->searchPlaceholder(__('Search by invoice number or customer name'))
            ->columns([
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
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Document status'))
                    ->options(
                        collect(InvoiceStatus::cases())
                            ->mapWithKeys(fn (InvoiceStatus $status): array => [$status->value => $status->label()])
                            ->all(),
                    ),
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                Filter::make('issue_date_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Issued from')),
                        DatePicker::make('until')->label(__('Issued until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('invoice_date', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('invoice_date', '<=', $date))),
                Filter::make('due_date_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Due from')),
                        DatePicker::make('until')->label(__('Due until')),
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

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
