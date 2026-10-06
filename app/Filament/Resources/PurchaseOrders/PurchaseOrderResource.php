<?php

declare(strict_types=1);

namespace App\Filament\Resources\PurchaseOrders;

use App\Filament\LocalizedResource as Resource;
use App\Filament\RelationManagers\CollaborationEntriesRelationManager;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderForm;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use App\Policies\PurchaseOrderPolicy;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The purchasing module's central surface.
 *
 * Uses the full page set with a Schemas / Tables / RelationManagers split,
 * matching `InventoryOperationResource` rather than the simpler single-page
 * `ManageX` shape (R-010): an order needs a View page for its lines, its
 * confirmation history, its linked receipts, and its audit trail.
 *
 * A sent order keeps its Edit route reachable but useless —
 * {@see PurchaseOrderPolicy::update()} refuses once the order has left draft,
 * so an operator who guesses the URL is refused by the same rule that hides the
 * button (R-C).
 *
 * @see /Docs/domains/purchasing/README.md User Story 2
 */
final class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.vendors';

    protected static ?int $navigationSort = 102;

    protected static ?string $recordTitleAttribute = 'purchase_order_number';

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.purchase_orders');
    }

    #[\Override]
    public static function getModelLabel(): string
    {
        return __('admin.resources.purchase_order');
    }

    #[\Override]
    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.purchase_orders');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return PurchaseOrderForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        // Lines and supplier responses stay in the workflow-focused infolist.
        // Collaboration is intentionally separate from the immutable audit trail.
        return [CollaborationEntriesRelationManager::class];
    }

    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'supplier',
            'paymentTerm',
            'lines.productVariant.unit',
            'lines.purchaseInboundLine.allocations',
            'purchaseInbound.lines.allocations.warehouse',
            'purchaseInbound.lines.allocations.inventoryOperationLines.operation',
            'purchaseInbound.lines.purchaseOrderLine.productVariant.product',
            'purchaseInbound.lines.purchaseOrderLine.productVariant.unit',
            'receipts.lines',
            'confirmations.items',
            'confirmations.confirmedBy',
            'bills.paymentAllocations.supplierPayment',
        ]);
    }

    /** @return array<string> */
    #[\Override]
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'purchase_order_number',
            'supplier.name',
            'supplier.code',
            'notes',
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
