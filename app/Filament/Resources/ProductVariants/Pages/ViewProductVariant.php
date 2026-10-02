<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\ProductVariant;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewProductVariant extends ViewRecord
{
    protected static string $resource = ProductVariantResource::class;

    #[\Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var ProductVariant $variant */
        $variant = $this->getRecord();

        $this->redirect(ProductVariantResource::parentProductVariantsUrl($variant));
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
