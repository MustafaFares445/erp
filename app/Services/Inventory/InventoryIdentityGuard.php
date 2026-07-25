<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use DomainException;

final readonly class InventoryIdentityGuard
{
    public function __construct(private InventoryAlertService $inventoryAlertService) {}

    public function ensureSkuAvailable(string $sku, ?int $ignoreVariantId = null): void
    {
        $existing = ProductVariant::query()
            ->withTrashed()
            ->where('sku', $sku)
            ->when($ignoreVariantId !== null, fn ($query) => $query->whereKeyNot($ignoreVariantId))
            ->first();

        if (! $existing instanceof ProductVariant) {
            return;
        }

        $this->reject('sku', $sku, $existing);
    }

    public function ensureSerialAvailable(string $serial, ?int $ignoreUnitId = null): void
    {
        $existing = SerializedInventoryUnit::query()
            ->withTrashed()
            ->where('serial_number', $serial)
            ->when($ignoreUnitId !== null, fn ($query) => $query->whereKeyNot($ignoreUnitId))
            ->first();

        if (! $existing instanceof SerializedInventoryUnit) {
            return;
        }

        $this->reject('serial', $serial, $existing);
    }

    public function ensureIotAvailable(?string $iot, ?int $ignoreUnitId = null): void
    {
        if ($iot === null || mb_trim($iot) === '') {
            return;
        }

        $existing = SerializedInventoryUnit::query()
            ->withTrashed()
            ->where('iot_number', $iot)
            ->when($ignoreUnitId !== null, fn ($query) => $query->whereKeyNot($ignoreUnitId))
            ->first();

        if (! $existing instanceof SerializedInventoryUnit) {
            return;
        }

        $this->reject('iot', $iot, $existing);
    }

    private function reject(string $kind, string $value, ProductVariant|SerializedInventoryUnit $existing): never
    {
        $this->inventoryAlertService->raiseDuplicateIdentity($kind, $value, $existing);

        throw new DomainException(__('admin.inventory.identity.errors.duplicate', [
            'kind' => $kind,
            'value' => $value,
        ]));
    }
}
