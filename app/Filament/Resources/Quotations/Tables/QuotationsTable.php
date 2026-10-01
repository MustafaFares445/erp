<?php

declare(strict_types=1);

namespace App\Filament\Resources\Quotations\Tables;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\Quotation;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class QuotationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Search by quotation number, customer name, or customer code'))
            ->searchDebounce('300ms')
            ->columns([
                TextColumn::make('quotation_number')->label(__('admin.sales.fields.quotation_number'))->searchable()->sortable(),
                TextColumn::make('customer.company_name')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable(['customer.company_name', 'customer.customer_code'])
                    ->description(fn (Quotation $record): ?string => $record->customer?->customer_code),
                TextColumn::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (QuotationStatus $state): string => $state->label())
                    ->color(static fn (QuotationStatus $state): string => $state->color()),
                TextColumn::make('reservation_coverage')
                    ->label(__('Stock coverage'))
                    ->state(fn (Quotation $record): string => match (true) {
                        $record->status !== QuotationStatus::Accepted && $record->converted_order_id === null => 'Not checked',
                        $record->hasLapsedReservations() => 'Insufficient',
                        default => 'Available',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Insufficient' => 'danger',
                        'Available' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('issue_date')->label(__('admin.sales.fields.issue_date'))->date()->sortable(),
                TextColumn::make('expires_at')->label(__('admin.sales.fields.expires_at'))->date()->sortable(),
                TextColumn::make('grand_total')
                    ->label(__('admin.sales.fields.grand_total'))
                    ->money()
                    ->sortable()
                    ->summarize(Sum::make()->money()->label(__('Total'))),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.sales.fields.status'))
                    ->options(array_combine(
                        array_map(fn (QuotationStatus $status): string => $status->value, QuotationStatus::cases()),
                        array_map(fn (QuotationStatus $status): string => $status->label(), QuotationStatus::cases()),
                    )),
                SelectFilter::make('customer_id')
                    ->label(__('admin.sales.fields.customer'))
                    ->searchable()
                    ->options(fn (): array => CustomerProfile::query()->orderBy('company_name')->pluck('company_name', 'id')->all()),
                SelectFilter::make('employee_id')
                    ->label(__('Salesperson'))
                    ->searchable()
                    ->options(fn (): array => EmployeeProfile::query()
                        ->with('user:id,name')
                        ->get()
                        ->mapWithKeys(static fn (EmployeeProfile $employee): array => [$employee->id => (string) $employee->user?->name])
                        ->all()),
                Filter::make('issue_date_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Issued from')),
                        DatePicker::make('until')->label(__('Issued until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('issue_date', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('issue_date', '<=', $date))),
                Filter::make('expires_at_between')
                    ->schema([
                        DatePicker::make('from')->label(__('Expires from')),
                        DatePicker::make('until')->label(__('Expires until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('expires_at', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('expires_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                QuotationActions::send(),
                QuotationActions::recordDecision(),
                QuotationActions::convert(),
                QuotationActions::requote(),
            ]);
    }

    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
