<?php

declare(strict_types=1);

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('admin.package.fields.name'))
                    ->required()
                    ->maxLength(150),
                Select::make('package_type_id')
                    ->label(__('admin.package.fields.package_type'))
                    ->relationship('packageType', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->required()
                    ->searchable()
                    ->preload(),
                Select::make('warehouse_id')
                    ->label(__('admin.package.fields.warehouse'))
                    ->relationship('warehouse', 'name', fn (Builder $query): Builder => $query->where('is_active', true))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->disabled(fn (?Package $record): bool => $record?->isReferenced() ?? false),
                Toggle::make('is_active')
                    ->label(__('admin.package.fields.is_active'))
                    ->default(true),
            ]);
    }
}
