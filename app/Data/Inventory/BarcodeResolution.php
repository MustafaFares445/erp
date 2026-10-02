<?php

declare(strict_types=1);

namespace App\Data\Inventory;

final readonly class BarcodeResolution
{
    public function __construct(
        public string $code,
        public string $kind,
        public int $productVariantId,
        public string $sku,
        public ?string $barcode,
        public string $variantName,
        public ?int $serializedInventoryUnitId = null,
        public ?string $serialNumber = null,
    ) {}

    public function isSerialized(): bool
    {
        return $this->serializedInventoryUnitId !== null;
    }
}
