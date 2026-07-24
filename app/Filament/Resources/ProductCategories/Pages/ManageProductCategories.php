<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageProductCategories extends ManageRecords
{
    protected static string $resource = ProductCategoryResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
