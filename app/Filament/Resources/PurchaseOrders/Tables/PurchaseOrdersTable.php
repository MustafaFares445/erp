<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Actions\PurchaseOrderActions;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use App\Support\QuantityFormatter;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use WeakMap;

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
                TextColumn::make('status')
                    ->label('Commercial status')
                    ->badge()
                    ->formatStateUsing(static fn (PurchaseOrderStatus $state): string => $state->label())
                    ->color(static fn (PurchaseOrderStatus $state): string => match ($state) {
                        PurchaseOrderStatus::Draft => 'gray',
                        PurchaseOrderStatus::PendingApproval => 'warning',
                        PurchaseOrderStatus::Accepted => 'info',
                        PurchaseOrderStatus::PartiallyReceived => 'primary',
                        PurchaseOrderStatus::Received => 'success',
                        PurchaseOrderStatus::Rejected, PurchaseOrderStatus::Cancelled => 'danger',
                        PurchaseOrderStatus::Closed => 'gray',
                    }),
                TextColumn::make('supplier_commitment')
                    ->label('Supplier')
                    ->getStateUsing(fn (PurchaseOrder $record): string => self::projection($record)->supplierState)
                    ->badge()
                    ->color(fn (PurchaseOrder $record): string => self::supplierColor(self::projection($record)->supplierState)),
                TextColumn::make('receiving_progress')
                    ->label('Receiving')
                    ->getStateUsing(function (PurchaseOrder $record): string {
                        $projection = self::projection($record);

                        return QuantityFormatter::display($projection->receivedBaseQuantity)
                            .' / '.QuantityFormatter::display($projection->confirmedBaseQuantity);
                    }),
                TextColumn::make('financial_state')
                    ->label('Accounting')
                    ->getStateUsing(fn (PurchaseOrder $record): string => self::projection($record)->financialState)
                    ->badge()
                    ->visible(fn (): bool => auth()->user()?->can('viewAny', Bill::class) ?? false),
                TextColumn::make('total_amount')
                    ->label(__('admin.purchasing.fields.total_amount'))
                    ->money(static fn (PurchaseOrder $record): string => $record->currency_code)
                    ->sortable(),
                TextColumn::make('expected_at')
                    ->label(__('admin.purchasing.fields.expected_at'))
                    ->date()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('blocker')
                    ->label('Blocker')
                    ->getStateUsing(fn (PurchaseOrder $record): ?string => self::projection($record)->blocker)
                    ->placeholder('None')
                    ->wrap(),
                TextColumn::make('next_action')
                    ->label('Next action')
                    ->getStateUsing(function (PurchaseOrder $record): string {
                        $projection = self::projection($record);

                        return $projection->nextOwner.' · '.$projection->nextAction;
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.purchasing.fields.status'))
                    ->multiple()
                    ->options(static fn (): array => self::statusOptions()),
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
                PurchaseOrderActions::submit(),
                PurchaseOrderActions::approve(),
                PurchaseOrderActions::send(),
            ]);
    }

    private static function projection(PurchaseOrder $record): PurchaseOrderWorkflowData
    {
        /** @var WeakMap<PurchaseOrder, PurchaseOrderWorkflowData>|null $cache */
        static $cache = null;

        $cache ??= new WeakMap;

        $cached = $cache[$record] ?? null;

        if ($cached instanceof PurchaseOrderWorkflowData) {
            return $cached;
        }

        $projection = app(PurchaseOrderWorkflowService::class)->project($record);
        $cache[$record] = $projection;

        return $projection;
    }

    private static function supplierColor(string $state): string
    {
        return match (true) {
            str_contains($state, 'Rejected') || str_contains($state, 'rejected') => 'danger',
            str_contains($state, 'Awaiting') => 'warning',
            str_contains($state, 'backordered') => 'warning',
            str_contains($state, 'Confirmed') || str_contains($state, 'not required') => 'success',
            default => 'gray',
        };
    }

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
