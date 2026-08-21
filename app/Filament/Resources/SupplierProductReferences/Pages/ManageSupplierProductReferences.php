<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierProductReferences\Pages;

use App\Filament\Resources\SupplierProductReferences\SupplierProductReferenceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageSupplierProductReferences extends ManageRecords
{
    protected static string $resource = SupplierProductReferenceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
