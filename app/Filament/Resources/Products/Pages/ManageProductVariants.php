<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;

final class ManageProductVariants extends ManageRelatedRecords
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'variants';

    protected static ?string $relatedResource = ProductVariantResource::class;

    /** @return array<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ProductVariantResource::createAction()
                ->label('Add product variant'),
        ];
    }
}
