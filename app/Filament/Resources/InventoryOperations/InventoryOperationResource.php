<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations;

use App\Enums\OperationType;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class InventoryOperationResource extends Resource
{
    protected static ?string $model = InventoryOperation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'operation_number';

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
            self::navigationItem('receipts', 'admin.resources.inventory_receipts_menu', Heroicon::OutlinedInboxArrowDown),
            self::navigationItem('deliveries', 'admin.resources.inventory_deliveries', Heroicon::OutlinedArrowUpTray),
            self::navigationItem('transfers', 'admin.resources.internal_transfers', Heroicon::OutlinedArrowsRightLeft),
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

    public static function currentOperationType(): ?OperationType
    {
        return match (true) {
            request()->routeIs(self::getRouteBaseName().'.receipts') => OperationType::Receipt,
            request()->routeIs(self::getRouteBaseName().'.deliveries') => OperationType::Delivery,
            request()->routeIs(self::getRouteBaseName().'.transfers') => OperationType::InternalTransfer,
            default => self::operationTypeFromRequest(),
        };
    }

    /**
     * Resolves the operation type for pages `currentOperationType()`'s route-name match can't
     * cover: create (type only known from the `?operation_type=` query string) and view/edit
     * (type only known from the record the route already bound).
     */
    private static function operationTypeFromRequest(): ?OperationType
    {
        $queryType = request()->query('operation_type');

        if (is_string($queryType) && ($type = OperationType::tryFrom($queryType)) !== null) {
            return $type;
        }

        $record = request()->route('record');

        return $record instanceof InventoryOperation ? $record->operation_type : null;
    }

    /**
     * The title shown for one record: the operation type plus its number once assigned (e.g.
     * "Receipt OP-000123"), or just the type while still a numberless Draft — never the generic,
     * type-agnostic model label a bare `Edit`/`View :label` page title would otherwise fall back
     * to.
     */
    #[\Override]
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof InventoryOperation) {
            return parent::getRecordTitle($record);
        }

        $typeLabel = $record->operation_type->label();

        return filled($record->operation_number)
            ? sprintf('%s %s', $typeLabel, $record->operation_number)
            : $typeLabel;
    }

    #[\Override]
    public static function getBreadcrumb(): string
    {
        return match (self::currentOperationType()) {
            OperationType::Receipt => __('admin.resources.inventory_receipts_menu'),
            OperationType::Delivery => __('admin.resources.inventory_deliveries'),
            OperationType::InternalTransfer => __('admin.resources.internal_transfers'),
            null => __('admin.resources.inventory_operations'),
        };
    }

    #[\Override]
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    private static function navigationItem(string $page, string $label, Heroicon $icon): NavigationItem
    {
        return NavigationItem::make(__($label))
            ->group('admin.groups.inventory')
            ->icon($icon)
            ->isActiveWhen(fn (): bool => request()->routeIs(self::getRouteBaseName().'.'.$page))
            ->url(self::getUrl($page));
    }
}
