<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

final class ListInventoryCounts extends ListRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = InventoryCountResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('admin.inventory.count_ui.actions.open')),
        ];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'inventory.inventory-counts';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
        ];
    }
}
