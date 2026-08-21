<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('ordered_at', 'desc')
            ->columns([
                TextColumn::make('purchase_order_number')
                    ->label(__('admin.purchasing.fields.purchase_order_number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label(__('admin.purchasing.fields.supplier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('destinationWarehouse.name')
                    ->label(__('admin.purchasing.fields.destination_warehouse')),
                TextColumn::make('status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->badge()
                    ->formatStateUsing(static fn (PurchaseOrderStatus $state): string => $state->label())
                    ->color(static fn (PurchaseOrderStatus $state): string => match ($state) {
                        PurchaseOrderStatus::Draft => 'gray',
                        PurchaseOrderStatus::PendingApproval => 'warning',
                        PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent => 'info',
                        PurchaseOrderStatus::PartiallyReceived => 'primary',
                        PurchaseOrderStatus::Received => 'success',
                        PurchaseOrderStatus::Rejected, PurchaseOrderStatus::Cancelled => 'danger',
                        PurchaseOrderStatus::Closed => 'gray',
                    }),
                // A supplier who declined is information the buyer acts on, not a
                // lifecycle state (FR-034), so it shows as a flag beside the
                // status rather than replacing it.
                IconColumn::make('supplier_rejected')
                    ->label(__('admin.purchasing.confirmation_status.rejected'))
                    ->boolean()
                    ->getStateUsing(static fn (PurchaseOrder $record): bool => $record->hasRejectedConfirmation())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currency_code')
                    ->label(__('admin.purchasing.fields.currency_code'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label(__('admin.purchasing.fields.total_amount'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('ordered_at')
                    ->label(__('admin.purchasing.fields.ordered_at'))
                    ->date()
                    ->sortable(),
                TextColumn::make('expected_at')
                    ->label(__('admin.purchasing.fields.expected_at'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('lines_count')
                    ->label(__('admin.purchasing.fields.lines'))
                    ->counts('lines')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->multiple()
                    ->options(static fn (): array => self::statusOptions()),
                SelectFilter::make('destination_warehouse_id')
                    ->label(__('admin.purchasing.fields.destination_warehouse'))
                    ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('currency_code')
                    ->label(__('admin.purchasing.fields.currency_code'))
                    ->options(fn (): array => self::currencyOptions()),
                Filter::make('ordered_between')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.purchasing.fields.ordered_at')),
                        DatePicker::make('until')->label(__('admin.purchasing.fields.expected_at')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when(self::dateFrom($data['from'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('ordered_at', '>=', $date))
                        ->when(self::dateFrom($data['until'] ?? null), static fn (Builder $q, string $date): Builder => $q->whereDate('ordered_at', '<=', $date))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Edit and Delete are refused for a non-draft order by
                // PurchaseOrderPolicy outright rather than by permission (R-C).
                EditAction::make(),
                PurchaseOrderActions::submit(),
                PurchaseOrderActions::approve(),
                PurchaseOrderActions::reject(),
                PurchaseOrderActions::send(),
                PurchaseOrderActions::receive(),
                PurchaseOrderActions::close(),
                PurchaseOrderActions::cancel(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }

    /**
     * Filament hands filter state over untyped; `whereDate()` wants a date it can
     * bind. Narrowing here keeps the query honest about what it received.
     */
    private static function dateFrom(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (PurchaseOrderStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function currencyOptions(): array
    {
        $options = [];

        foreach (PurchaseOrder::query()->distinct()->orderBy('currency_code')->pluck('currency_code') as $code) {
            if (is_string($code)) {
                $options[$code] = $code;
            }
        }

        return $options;
    }
}
