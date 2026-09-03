<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges\Pages;

use App\Data\Inventory\QuarantineDispositionData;
use App\Enums\ConditionChangeReason;
use App\Enums\QuarantineDisposition;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Models\InventoryConditionChange;
use App\Models\User;
use App\Services\Inventory\InventoryConditionChangeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateInventoryConditionChange extends CreateRecord
{
    protected static string $resource = InventoryConditionChangeResource::class;

    /**
     * @param  array<string,mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory condition-change actor is required.');
        }

        return app(InventoryConditionChangeService::class)->draftQuarantineDisposition(
            new QuarantineDispositionData(
                productVariantId: (int) ($data['product_variant_id'] ?? 0),
                warehouseId: (int) ($data['warehouse_id'] ?? 0),
                inventoryLotId: is_numeric($data['inventory_lot_id'] ?? null)
                    ? (int) $data['inventory_lot_id']
                    : null,
                serializedInventoryUnitId: is_numeric($data['serialized_inventory_unit_id'] ?? null)
                    ? (int) $data['serialized_inventory_unit_id']
                    : null,
                baseQuantity: (string) ($data['base_quantity'] ?? ''),
                disposition: QuarantineDisposition::from((string) ($data['disposition'] ?? '')),
                reasonCategory: ConditionChangeReason::from((string) ($data['reason_category'] ?? '')),
                reason: (string) ($data['reason'] ?? ''),
            ),
            $actor,
        );
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        /** @var InventoryConditionChange $record */
        $record = $this->record;

        return InventoryConditionChangeResource::getUrl('view', ['record' => $record]);
    }
}
