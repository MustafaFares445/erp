<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations;

use App\Filament\Resources\InventoryOperations\Pages\CreateInventoryOperation;
use App\Filament\Resources\InventoryOperations\Pages\EditInventoryOperation;
use App\Filament\Resources\InventoryOperations\Pages\ListDeliveries;
use App\Filament\Resources\InventoryOperations\Pages\ListInternalTransfers;
use App\Filament\Resources\InventoryOperations\Pages\ListInventoryOperations;
use App\Filament\Resources\InventoryOperations\Pages\ListReceipts;
use App\Filament\Resources\InventoryOperations\Pages\ViewInventoryOperation;
use App\Filament\Resources\InventoryOperations\Schemas\InventoryOperationForm;
use App\Filament\Resources\InventoryOperations\Schemas\InventoryOperationInfolist;
use App\Filament\Resources\InventoryOperations\Tables\InventoryOperationsTable;
use App\Models\InventoryOperation;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class InventoryOperationResource extends Resource
{
    protected static ?string $model = InventoryOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return InventoryOperationForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return InventoryOperationInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return InventoryOperationsTable::configure($table);
    }

    /** @return array<NavigationItem> */
    #[\Override]
    public static function getNavigationItems(): array
    {
        return [
            self::navigationItem('receipts', 'admin.resources.inventory_receipts_menu'),
            self::navigationItem('deliveries', 'admin.resources.inventory_deliveries'),
            self::navigationItem('transfers', 'admin.resources.internal_transfers'),
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryOperations::route('/'),
            'receipts' => ListReceipts::route('/receipts'),
            'deliveries' => ListDeliveries::route('/deliveries'),
            'transfers' => ListInternalTransfers::route('/internal-transfers'),
            'create' => CreateInventoryOperation::route('/create'),
            'view' => ViewInventoryOperation::route('/{record}'),
            'edit' => EditInventoryOperation::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    private static function navigationItem(string $page, string $label): NavigationItem
    {
        return NavigationItem::make(__($label))
            ->group('admin.groups.inventory')
            ->icon(self::$navigationIcon)
            ->isActiveWhen(fn (): bool => request()->routeIs(self::getRouteBaseName().'.'.$page))
            ->url(self::getUrl($page));
    }
}
