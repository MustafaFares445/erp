<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductVariants\Pages;

use App\Filament\Resources\ProductVariants\ProductVariantResource;
use App\Models\ProductVariant;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use LogicException;

final class ViewProductVariant extends ViewRecord
{
    protected static string $resource = ProductVariantResource::class;

    #[\Override]
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $variant = $this->getRecord();

        // @codeCoverageIgnoreStart
        // Unreachable in practice: this page's resource is fixed to
        // ProductVariant, so Filament's route-model binding always resolves
        // $this->getRecord() to a ProductVariant here. The guard exists only
        // to satisfy static analysis (getRecord() is typed to the generic
        // Model contract) without widening the redirect helper's signature.
        if (! $variant instanceof ProductVariant) {
            throw new LogicException('The product variant record could not be resolved.');
        }

        // @codeCoverageIgnoreEnd

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
