<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryOperations\Pages;

use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Tables\InventoryOperationsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

final class ListInventoryOperations extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = InventoryOperationResource::class;

    #[\Override]
    public static function canAccess(array $parameters = []): bool
    {
        return InventoryOperationResource::canViewInventoryIndex();
    }

    #[\Override]
    public function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'inventory.operations';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return InventoryOperationsTable::presetTabs();
    }
}
