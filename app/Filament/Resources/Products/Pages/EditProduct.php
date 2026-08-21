<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\Inventory\ProductMediaSynchronizer;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** @param array<string, mixed> $data */
    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Product) {
            return parent::handleRecordUpdate($record, $data);
        }

        $images = Arr::wrap(Arr::pull($data, 'images', []));
        $record->update(ProductForm::productData($data));
        app(ProductMediaSynchronizer::class)->sync($record, $images);

        return $record;
    }
}
