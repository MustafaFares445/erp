<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageProductVariants extends ManageRecords
{
    protected static string $resource = ProductVariantResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
