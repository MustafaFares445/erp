<?php

declare(strict_types=1);

namespace App\Filament\Resources\Adjustments;

use App\Filament\Resources\Adjustments\Pages\CreateAdjustment;
use App\Filament\Resources\Adjustments\Pages\EditAdjustment;
use App\Filament\Resources\Adjustments\Pages\ListAdjustments;
use App\Filament\Resources\Adjustments\Pages\ViewAdjustment;
use App\Filament\Resources\Adjustments\RelationManagers\AdjustmentItemsRelationManager;
use App\Filament\Resources\Adjustments\Schemas\AdjustmentForm;
use App\Filament\Resources\Adjustments\Schemas\AdjustmentInfolist;
use App\Filament\Resources\Adjustments\Tables\AdjustmentsTable;
use App\Models\InventoryAdjustment;
use App\Policies\InventoryAdjustmentPolicy;
use App\Services\Inventory\InventoryAdjustmentService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/**
 * The draft→confirm stock-adjustment screen (FI-3). All authorization flows
 * through {@see InventoryAdjustmentPolicy}; the confirm action
 * (wired on the pages) is a thin adapter over
 * {@see InventoryAdjustmentService} — this resource
 * computes nothing and, per the architecture guard in
 * tests/Unit/ArchTest.php, must never reference the read/write-model ledger
 * classes directly.
 *
 * @see /specs/003-stock-adjustments/contracts/adjustment-resource.md
 */
final class AdjustmentResource extends Resource
{
    protected static ?string $model = InventoryAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'admin.groups.inventory';

    /**
     * Inventory group sort (3) * 100 + this item's index (6) in
     * AdminModuleRegistry's `inventory` group items list.
     */
    protected static ?int $navigationSort = 306;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.adjustments');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return AdjustmentForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return AdjustmentInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return AdjustmentsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            AdjustmentItemsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAdjustments::route('/'),
            'create' => CreateAdjustment::route('/create'),
            'view' => ViewAdjustment::route('/{record}'),
            'edit' => EditAdjustment::route('/{record}/edit'),
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
