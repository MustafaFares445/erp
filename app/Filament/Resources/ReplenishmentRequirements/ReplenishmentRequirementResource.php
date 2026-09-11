<?php

declare(strict_types=1);

namespace App\Filament\Resources\ReplenishmentRequirements;

use App\Filament\Resources\ReplenishmentRequirements\Pages\ListReplenishmentRequirements;
use App\Filament\Resources\ReplenishmentRequirements\Pages\ViewReplenishmentRequirement;
use App\Filament\Resources\ReplenishmentRequirements\Schemas\ReplenishmentRequirementInfolist;
use App\Filament\Resources\ReplenishmentRequirements\Tables\ReplenishmentRequirementsTable;
use App\Models\ReplenishmentRequirement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class ReplenishmentRequirementResource extends Resource
{
    protected static ?string $model = ReplenishmentRequirement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 304;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return 'Replenishment Requirements';
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ReplenishmentRequirementsTable::configure($table);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ReplenishmentRequirementInfolist::configure($schema);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'warehouse:id,name,code',
            'productVariant:id,sku,name',
            'policy:id,warehouse_id,product_variant_id,min_quantity,max_quantity,is_active',
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListReplenishmentRequirements::route('/'),
            'view' => ViewReplenishmentRequirement::route('/{record}'),
        ];
    }
}
