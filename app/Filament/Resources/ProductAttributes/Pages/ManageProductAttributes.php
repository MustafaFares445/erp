<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductAttributes\Pages;

use App\Filament\Resources\ProductAttributes\ProductAttributeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageProductAttributes extends ManageRecords
{
    protected static string $resource = ProductAttributeResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
