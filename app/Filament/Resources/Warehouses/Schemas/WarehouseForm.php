<?php

declare(strict_types=1);

namespace App\Filament\Resources\Warehouses\Schemas;

use App\Data\Inventory\WarehouseData;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        $rules = WarehouseData::rules();

        return $schema
            ->components([
                TextInput::make('name')
                    ->rules($rules['name'])
                    ->maxLength(255),
                TextInput::make('code')
                    ->rules($rules['code'])
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),
                Textarea::make('address')
                    ->rules($rules['address'])
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->default(true),
            ]);
    }
}
