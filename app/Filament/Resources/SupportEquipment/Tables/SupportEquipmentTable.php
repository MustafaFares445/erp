<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportEquipment\Tables;

use App\Enums\SerializedCustodyType;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class SupportEquipmentTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('serial_number')
                    ->label(__('Serial'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('productVariant.product.name')
                    ->label(__('Product'))
                    ->searchable()
                    ->description(static fn (SerializedInventoryUnit $record): ?string => $record->productVariant?->sku),
                TextColumn::make('support_customer')
                    ->label(__('Customer'))
                    ->state(static function (SerializedInventoryUnit $record): string {
                        if ($record->custody_type !== SerializedCustodyType::Customer || ! is_numeric($record->custody_reference_id)) {
                            return __('Not currently in customer custody');
                        }

                        $companyName = CustomerProfile::query()->whereKey((int) $record->custody_reference_id)->value('company_name');

                        return is_string($companyName) ? $companyName : __('Unknown customer');
                    }),
                TextColumn::make('currentWarrantyEntitlement.state')
                    ->label(__('Warranty'))
                    ->badge()
                    ->placeholder(__('Legacy / not configured')),
                TextColumn::make('active_tickets_count')
                    ->label(__('Open tickets'))
                    ->badge()
                    ->color(static fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('active_maintenance_count')
                    ->label(__('Active jobs'))
                    ->badge()
                    ->color(static fn (int $state): string => $state > 0 ? 'primary' : 'gray'),
                TextColumn::make('warranty_expires_on')->label(__('Warranty expiry'))->date()->placeholder(__('—')),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('customer_custody')
                    ->label(__('Currently with a customer'))
                    ->queries(
                        true: static fn (Builder $query): Builder => $query->where('custody_type', SerializedCustodyType::Customer->value),
                        false: static fn (Builder $query): Builder => $query->where('custody_type', '!=', SerializedCustodyType::Customer->value),
                    ),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
