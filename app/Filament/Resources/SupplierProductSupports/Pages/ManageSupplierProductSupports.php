<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductSupports\Pages;

use App\Filament\Resources\SupplierProductSupports\SupplierProductSupportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierProductSupports extends ManageRecords
{
    protected static string $resource = SupplierProductSupportResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
