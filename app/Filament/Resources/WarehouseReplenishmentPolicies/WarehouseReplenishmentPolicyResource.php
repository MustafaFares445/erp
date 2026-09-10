<?php

declare(strict_types=1);

namespace App\Filament\Resources\WarehouseReplenishmentPolicies;

use App\Filament\Resources\WarehouseReplenishmentPolicies\Pages\ManageWarehouseReplenishmentPolicies;
use App\Filament\Resources\WarehouseReplenishmentPolicies\Schemas\WarehouseReplenishmentPolicyForm;
use App\Filament\Resources\WarehouseReplenishmentPolicies\Tables\WarehouseReplenishmentPoliciesTable;
use App\Models\WarehouseReplenishmentPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Lets an Inventory Manager assign each warehouse/variant pair its own
 * replenishment minimum and maximum, independent of whether any
 * `App\Models\InventoryStock` row exists yet (Phase 0 remediation).
 */
final class WarehouseReplenishmentPolicyResource extends Resource
{
    protected static ?string $model = WarehouseReplenishmentPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 305;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.replenishment_policies');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.replenishment_policies');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return WarehouseReplenishmentPolicyForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return WarehouseReplenishmentPoliciesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ManageWarehouseReplenishmentPolicies::route('/'),
        ];
    }
}
