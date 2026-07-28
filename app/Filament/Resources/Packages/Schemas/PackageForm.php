<?php

declare(strict_types=1);

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use App\Models\WarehouseLocation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

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
                    ->live()
                    ->disabled(fn (?Package $record): bool => $record?->isReferenced() ?? false),
                Select::make('warehouse_location_id')
                    ->label(__('admin.package.fields.location'))
                    ->options(fn (Get $get): array => self::locationOptions($get('warehouse_id')))
                    ->searchable()
                    ->disabled(fn (Get $get): bool => ! is_numeric($get('warehouse_id')))
                    ->rules(fn (Get $get): array => self::locationRules($get('warehouse_id'))),
                Toggle::make('is_active')
                    ->label(__('admin.package.fields.is_active'))
                    ->default(true),
            ]);
    }

    /** @return array<int, string> */
    private static function locationOptions(mixed $warehouseId): array
    {
        if (! is_numeric($warehouseId)) {
            return [];
        }

        return WarehouseLocation::query()
            ->where('warehouse_id', (int) $warehouseId)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(static function (mixed $name, mixed $id): array {
                if (! is_numeric($id) || ! is_scalar($name)) {
                    return [];
                }

                return [(int) $id => (string) $name];
            })
            ->all();
    }

    /** @return array<int, mixed> */
    private static function locationRules(mixed $warehouseId): array
    {
        if (! is_numeric($warehouseId)) {
            return [];
        }

        return [
            Rule::exists('warehouse_locations', 'id')
                ->where('warehouse_id', (int) $warehouseId)
                ->where('is_active', true),
        ];
    }
}
