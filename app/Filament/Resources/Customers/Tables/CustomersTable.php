<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\OperationStage;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InvoiceDeliveryLink;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

final class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer_code')->label('Customer code')->searchable()->sortable(),
                TextColumn::make('company_name')->label('Company name')->searchable()->sortable(),
                TextColumn::make('user.name')->label('Account name')->searchable(),
                TextColumn::make('user.username')->label('Username')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.email')->label('Account email')->searchable(),
                TextColumn::make('email')->label('Company email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('phone')->searchable()->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_active')->label('Active'),
                TextColumn::make('deliveries_awaiting_invoice')
                    ->label('Deliveries awaiting invoice')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray')
                    ->state(fn (CustomerProfile $record): int => self::deliveriesAwaitingInvoiceCount($record)),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Completed deliveries for this customer with no {@see InvoiceDeliveryLink}
     * row yet (WP-2.13, GAP-MW-13) — the operator-facing half of the leak that consolidated
     * invoicing closes.
     */
    private static function deliveriesAwaitingInvoiceCount(CustomerProfile $record): int
    {
        return InventoryOperation::query()
            ->where('customer_id', $record->getKey())
            ->where('stage', OperationStage::Done->value)
            ->whereDoesntHave('invoiceDeliveryLink')
            ->count();
    }
}
