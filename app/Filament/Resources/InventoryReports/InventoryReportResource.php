<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryReports;

use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryReportService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class InventoryReportResource extends Resource
{
    protected static ?string $model = InventoryStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return __('admin.resources.inventory_reports');
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table;
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && app(InventoryReportService::class)->availableReports($actor) !== [];
    }

    #[\Override]
    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getPages(): array
    {
        return ['index' => ManageInventoryReports::route('/')];
    }
}
