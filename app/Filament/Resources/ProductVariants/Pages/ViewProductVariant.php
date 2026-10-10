<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewProductVariant extends ViewRecord
{
    protected static string $resource = ProductVariantResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ProductVariantResource::editAction(),
        ];
    }
}
