<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShipmentAttachments;

use App\Filament\Resources\ShipmentAttachments\Pages\ListShipmentAttachments;
use App\Filament\Resources\ShipmentAttachments\Pages\ViewShipmentAttachment;
use App\Filament\Resources\ShipmentAttachments\Schemas\ShipmentAttachmentInfolist;
use App\Filament\Resources\ShipmentAttachments\Tables\ShipmentAttachmentsTable;
use App\Models\Shipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class ShipmentAttachmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperClip;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 308;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.shipment_attachments');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ShipmentAttachmentsTable::configure($table);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ShipmentAttachmentInfolist::configure($schema);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListShipmentAttachments::route('/'),
            'view' => ViewShipmentAttachment::route('/{record}'),
        ];
    }
}
