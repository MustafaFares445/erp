<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryCounts\Pages;

use App\Data\Inventory\CountScopeData;
use App\Enums\CountScope;
use App\Filament\Resources\InventoryCounts\InventoryCountResource;
use App\Models\InventoryCount;
use App\Models\User;
use App\Services\Inventory\InventoryCountService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateInventoryCount extends CreateRecord
{
    protected static string $resource = InventoryCountResource::class;

    /** @param array<string,mixed> $data */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $conditions = $data['conditions'] ?? [];

        return app(InventoryCountService::class)->open(new CountScopeData(
            warehouseId: $this->requiredInt($data, 'warehouse_id'),
            scopeType: CountScope::from($this->stringValue($data, 'scope_type')),
            productCategoryId: $this->optionalInt($data, 'product_category_id'),
            inventoryLotId: $this->optionalInt($data, 'inventory_lot_id'),
            productVariantIds: null,
            conditions: is_array($conditions) ? array_values(array_filter($conditions, is_string(...))) : [],
            materialityThresholdMinor: $this->optionalInt($data, 'materiality_threshold_minor'),
        ), $this->actor());
    }

    /** @param array<string,mixed> $data */
    private function requiredInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_numeric($value)) {
            throw new LogicException(sprintf('The "%s" field must be a number.', $key));
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $data */
    private function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string,mixed> $data */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated actor is required to open a physical count.');
        }

        return $actor;
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        /** @var InventoryCount $record */
        $record = $this->record;

        return InventoryCountResource::getUrl('view', ['record' => $record]);
    }
}
