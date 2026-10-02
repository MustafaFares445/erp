<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\HasSavedTableViews;
use App\Filament\Concerns\PersistsTablePresentation;
use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCustomers extends ListRecords
{
    use HasSavedTableViews;
    use PersistsTablePresentation;

    protected static string $resource = CustomerResource::class;

    #[\Override]
    public function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...$this->savedTableViewActions(),
        ];
    }

    protected function savedTableViewPageKey(): string
    {
        return 'crm.customers';
    }
}
