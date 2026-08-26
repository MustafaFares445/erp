<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryNotes;

use App\Enums\OperationType;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteInfolist;
use App\Filament\Resources\DeliveryNotes\Tables\DeliveryNotesTable;
use App\Models\InventoryOperation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class DeliveryNoteResource extends Resource
{
    protected static ?string $model = InventoryOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.sales';

    protected static ?int $navigationSort = 102;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.delivery_notes');
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('operation_type', OperationType::Delivery->value)
            ->with(['customer', 'sourceWarehouse', 'lines.productVariant', 'lines.unit']);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return DeliveryNoteInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return DeliveryNotesTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryNotes::route('/'),
            'view' => ViewDeliveryNote::route('/{record}'),
        ];
    }
}
