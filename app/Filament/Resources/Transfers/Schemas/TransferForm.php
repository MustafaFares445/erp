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
use Filament\Support\Icons\Heroicon;
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
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Choose the warehouse that currently holds the stock being moved.')
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
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'Choose the receiving warehouse. It cannot be the same as the source warehouse.')
                    ->disabled(fn (?StockTransfer $record): bool => $record?->isConfirmed() ?? false),
                TextInput::make('transfer_number')
                    ->label(__('admin.inventory.transfer.transfer_number'))
                    ->placeholder(__('admin.inventory.transfer.number_pending'))
                    ->disabled()
                    ->dehydrated(false)
                    ->hintIcon(Heroicon::QuestionMarkCircle, 'The transfer number is assigned automatically when the record is saved.'),
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
