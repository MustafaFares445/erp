<?php

declare(strict_types=1);

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Concerns\HasTableViewTabs;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;

final class ManageSuppliers extends ManageRecords
{
    use HasTableViewTabs;
    use PersistsTablePresentation;

    protected static string $resource = SupplierResource::class;

    protected function savedTableViewPageKey(): string
    {
        return 'purchasing.suppliers';
    }

    /** @return array<string, Tab> */
    #[\Override]
    public function getTabs(): array
    {
        return ['all' => Tab::make(__('All'))];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
