<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseInbounds;

use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchaseInbounds\Pages\ListPurchaseInbounds;
use App\Filament\Resources\PurchaseInbounds\Pages\ViewPurchaseInbound;
use App\Filament\Resources\PurchaseInbounds\RelationManagers\PurchaseInboundLinesRelationManager;
use App\Filament\Resources\PurchaseInbounds\Schemas\PurchaseInboundInfolist;
use App\Filament\Resources\PurchaseInbounds\Tables\PurchaseInboundsTable;
use App\Models\PurchaseInbound;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class PurchaseInboundResource extends Resource
{
    protected static ?string $model = PurchaseInbound::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    protected static ?int $navigationSort = 302;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.purchase_inbounds');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.purchase_inbounds');
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PurchaseInboundInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PurchaseInboundsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [PurchaseInboundLinesRelationManager::class];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseInbounds::route('/'),
            'view' => ViewPurchaseInbound::route('/{record}'),
        ];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where(function (Builder $query): void {
                $query->whereHas('purchaseOrder.supplier', static fn (Builder $supplier): Builder => $supplier
                    ->where('requires_confirmation', false))
                    ->orWhereHas('lines.purchaseOrderLine', static fn (Builder $line): Builder => $line
                        ->whereHas('supplierConfirmationItems', static fn (Builder $item): Builder => $item
                            ->whereIn('confirmation_status', [
                                SupplierConfirmationStatus::Confirmed->value,
                                SupplierConfirmationStatus::Partial->value,
                            ])
                            ->where('confirmed_base_quantity', '>', 0)));
            })
            ->with([
                'purchaseOrder.supplier',
                'lines.purchaseOrderLine.productVariant.product',
                'lines.purchaseOrderLine.productVariant.unit',
                'lines.allocations.warehouse',
            ]);
    }
}
