<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments\Schemas;

use App\Data\Inventory\AdjustmentData;
use App\Models\InventoryAdjustment;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class AdjustmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $rules = AdjustmentData::rules();

        return $schema
            ->components([
                Select::make('warehouse_id')
                    ->relationship('warehouse', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->rules($rules['warehouse_id'])
                    ->searchable()
                    ->preload()
                    ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false),
                TextInput::make('adjustment_number')
                    ->label(__('admin.inventory.adjustment.adjustment_number'))
                    ->placeholder(__('admin.inventory.adjustment.number_pending'))
                    ->disabled()
                    ->dehydrated(false),
                Textarea::make('reason')
                    ->label(__('admin.inventory.adjustment.reason'))
                    ->rules($rules['reason'])
                    ->maxLength(1000)
                    ->columnSpanFull()
                    ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false),
            ])
            ->disabled(fn (?InventoryAdjustment $record): bool => $record?->isConfirmed() ?? false);
    }
}
