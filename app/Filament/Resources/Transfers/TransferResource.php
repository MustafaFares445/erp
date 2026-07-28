<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transfers;

use App\Filament\Resources\Transfers\Pages\CreateTransfer;
use App\Filament\Resources\Transfers\Pages\EditTransfer;
use App\Filament\Resources\Transfers\Pages\ListTransfers;
use App\Filament\Resources\Transfers\Pages\ViewTransfer;
use App\Filament\Resources\Transfers\RelationManagers\TransferItemsRelationManager;
use App\Filament\Resources\Transfers\Schemas\TransferForm;
use App\Filament\Resources\Transfers\Schemas\TransferInfolist;
use App\Filament\Resources\Transfers\Tables\TransfersTable;
use App\Models\StockTransfer;
use App\Policies\StockTransferPolicy;
use App\Services\Inventory\StockTransferService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The draft→confirm stock-transfer screen (FI-4). All authorization flows
 * through {@see StockTransferPolicy}; the confirm action
 * (wired on the pages) is a thin adapter over
 * {@see StockTransferService} — this resource
 * computes nothing and, per the architecture guard in
 * tests/Unit/ArchTest.php, must never reference the read/write-model ledger
 * classes directly.
 *
 * @see /specs/004-stock-transfers/contracts/transfer-resource.md
 */
final class TransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    /**
     * Inventory group sort (3) * 100 + this item's index (5) in
     * AdminModuleRegistry's `inventory` group items list.
     */
    protected static ?int $navigationSort = 305;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.transfers');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return TransferForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return TransferInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return TransfersTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            TransferItemsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTransfers::route('/'),
            'create' => CreateTransfer::route('/create'),
            'view' => ViewTransfer::route('/{record}'),
            'edit' => EditTransfer::route('/{record}/edit'),
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
}
