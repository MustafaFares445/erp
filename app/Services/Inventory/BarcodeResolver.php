<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\BarcodeResolution;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use DomainException;

final readonly class BarcodeResolver
{
    public function resolve(string $rawCode): BarcodeResolution
    {
        $code = mb_trim($rawCode);
        if ($code === '') {
            throw new DomainException('A barcode, SKU, or serial number is required.');
        }

        $serialized = SerializedInventoryUnit::query()
            ->with('productVariant')
            ->where('serial_number', $code)
            ->first();

        if ($serialized instanceof SerializedInventoryUnit) {
            $variant = $serialized->productVariant;
            if (! $variant instanceof ProductVariant) {
                throw new DomainException('The scanned serial is not linked to a product variant.');
            }

            return $this->resolution($code, 'serial', $variant, $serialized);
        }

        $variant = ProductVariant::withTrashed()
            ->where('barcode', $code)
            ->first();
        if ($variant instanceof ProductVariant) {
            return $this->resolution($code, 'barcode', $variant);
        }

        $variant = ProductVariant::withTrashed()
            ->where('sku', $code)
            ->first();
        if ($variant instanceof ProductVariant) {
            return $this->resolution($code, 'sku', $variant);
        }

        throw new DomainException(sprintf('No inventory item matches scan [%s].', $code));
    }

    private function resolution(
        string $code,
        string $kind,
        ProductVariant $variant,
        ?SerializedInventoryUnit $serialized = null,
    ): BarcodeResolution {
        return new BarcodeResolution(
            code: $code,
            kind: $kind,
            productVariantId: $variant->id,
            sku: (string) $variant->sku,
            barcode: is_string($variant->barcode) ? $variant->barcode : null,
            variantName: (string) $variant->name,
            serializedInventoryUnitId: $serialized?->id,
            serialNumber: $serialized?->serial_number,
        );
    }
}
