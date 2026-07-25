<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryAlerts;

use App\Filament\Resources\InventoryAlerts\Pages\ListInventoryAlerts;
use App\Filament\Resources\InventoryAlerts\Pages\ViewInventoryAlert;
use App\Filament\Resources\InventoryAlerts\Schemas\InventoryAlertInfolist;
use App\Filament\Resources\InventoryAlerts\Tables\InventoryAlertsTable;
use App\Models\InventoryAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class InventoryAlertResource extends Resource
{
    protected static ?string $model = InventoryAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 399;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_alerts');
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return InventoryAlertInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return InventoryAlertsTable::configure($table);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('subject')
            ->latest();
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryAlerts::route('/'),
            'view' => ViewInventoryAlert::route('/{record}'),
        ];
    }
}
