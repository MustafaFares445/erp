<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shipments;

use App\Filament\Resources\ShipmentAttachments\Schemas\ShipmentAttachmentInfolist;
use App\Filament\Resources\Shipments\Pages\ListShipments;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Filament\Resources\Shipments\Tables\ShipmentsTable;
use App\Models\Shipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 308;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.shipments');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ShipmentsTable::configure($table);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ShipmentAttachmentInfolist::configure($schema);
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'order.customer',
            'warehouse',
            'delivery',
            'confirmedByAdminUser',
            'confirmedByCustomer',
            'arrivalConfirmation.media',
        ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'view' => ViewShipment::route('/{record}'),
        ];
    }
}
