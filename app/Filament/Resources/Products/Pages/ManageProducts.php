<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Enums\InventoryExportType;
use App\Filament\Concerns\RequestsInventoryExports;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\Inventory\ProductMediaSynchronizer;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Illuminate\Support\Arr;

final class ManageProducts extends ManageRecords
{
    use RequestsInventoryExports;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $resource = ProductResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->using(static function (array $data, ProductMediaSynchronizer $mediaSynchronizer): Product {
                $images = Arr::wrap(Arr::pull($data, 'images', []));
                $unitIds = Arr::wrap(Arr::pull($data, 'unit_ids', []));
                $defaultUnitId = ProductForm::normalizeUnitId(Arr::pull($data, 'default_unit_id'));
                $product = Product::query()->create(ProductForm::productData($data));

                $mediaSynchronizer->sync($product, $images);
                $product->syncUnits($unitIds, $defaultUnitId);

                return $product;
            }),
            $this->inventoryExportAction(InventoryExportType::Catalog),
        ];
    }
}
