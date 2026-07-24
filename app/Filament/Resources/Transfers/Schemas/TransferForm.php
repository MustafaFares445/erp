<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers\Schemas;

use App\Data\Inventory\TransferData;
use App\Models\StockTransfer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class TransferForm
{
    public static function configure(Schema $schema): Schema
    {
        $rules = TransferData::rules();

        return $schema
            ->components([
                Select::make('from_warehouse_id')
                    ->label(__('admin.inventory.transfer.from_warehouse'))
                    ->relationship('fromWarehouse', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->rules($rules['from_warehouse_id'])
                    ->searchable()
                    ->preload()
                    ->live()
                    ->disabled(fn (?StockTransfer $record): bool => $record?->isConfirmed() ?? false),
                Select::make('to_warehouse_id')
                    ->label(__('admin.inventory.transfer.to_warehouse'))
                    ->relationship('toWarehouse', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->rules($rules['to_warehouse_id'])
                    ->searchable()
                    ->preload()
                    ->disableOptionWhen(function (mixed $value, Get $get): bool {
                        $fromWarehouseId = $get('from_warehouse_id');

                        if (! is_numeric($value) || ! is_numeric($fromWarehouseId)) {
                            return false;
                        }

                        return (int) $value === (int) $fromWarehouseId;
                    })
                    ->disabled(fn (?StockTransfer $record): bool => $record?->isConfirmed() ?? false),
                TextInput::make('transfer_number')
                    ->label(__('admin.inventory.transfer.transfer_number'))
                    ->placeholder(__('admin.inventory.transfer.number_pending'))
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('notes')
                    ->label(__('admin.inventory.transfer.notes'))
                    ->rules($rules['notes'])
                    ->maxLength(1000)
                    ->columnSpanFull()
                    ->disabled(fn (?StockTransfer $record): bool => $record?->isConfirmed() ?? false),
            ])
            ->disabled(fn (?StockTransfer $record): bool => $record?->isConfirmed() ?? false);
    }
}
