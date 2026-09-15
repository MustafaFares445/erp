<?php

declare(strict_types=1);

namespace App\Services\Suppliers;

use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Services\Settings\CurrencyCatalogService;

final readonly class SupplierReferenceImportService
{
    public function __construct(private CurrencyCatalogService $currencies) {}

    /** @param array<string, string> $payload */
    public function sync(array $payload, ProductVariant $variant, ?Supplier $supplier): void
    {
        if (! $supplier instanceof Supplier) {
            return;
        }

        SupplierProductReference::query()->updateOrCreate(
            [
                'supplier_id' => $supplier->getKey(),
                'supplier_item_number' => $payload['supplier_item_number'] ?? $variant->sku,
            ],
            [
                'product_variant_id' => $variant->getKey(),
                'supplier_name' => $supplier->name,
                'country_code' => $payload['country_code'] ?? null,
                'purchase_cost' => $payload['cost_price'] ?? null,
                'currency_code' => $this->currencies->normalizeActive(
                    $payload['currency_code'] ?? $this->currencies->defaultCode(),
                    'currency_code',
                ),
            ],
        );
    }
}
