<?php

declare(strict_types=1);

namespace App\Filament\Resources\InventoryConditionChanges\Pages;

use App\Data\Inventory\DamageDraftData;
use App\Data\Inventory\DisposalDraftData;
use App\Data\Inventory\QuarantineDispositionData;
use App\Data\Inventory\RecoveryDraftData;
use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeType;
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
        $actor = $this->actor();
        $service = app(InventoryConditionChangeService::class);
        $type = InventoryConditionChangeType::tryFrom($this->stringValue($data, 'type'))
            ?? InventoryConditionChangeType::QuarantineDisposition;

        return match ($type) {
            InventoryConditionChangeType::Damage => $service->draftDamage($this->damageData($data), $actor),
            InventoryConditionChangeType::DamageRecovery => $service->draftRecovery($this->recoveryData($data), $actor),
            InventoryConditionChangeType::Disposal => $service->draftDisposal($this->disposalData($data), $actor),
            InventoryConditionChangeType::QuarantineDisposition => $service->draftQuarantineDisposition(
                $this->quarantineDispositionData($data),
                $actor,
            ),
        };
    }

    /** @param array<string,mixed> $data */
    private function quarantineDispositionData(array $data): QuarantineDispositionData
    {
        return new QuarantineDispositionData(
            productVariantId: $this->requiredInt($data, 'product_variant_id'),
            warehouseId: $this->requiredInt($data, 'warehouse_id'),
            inventoryLotId: $this->optionalInt($data, 'inventory_lot_id'),
            serializedInventoryUnitId: $this->optionalInt($data, 'serialized_inventory_unit_id'),
            baseQuantity: $this->stringValue($data, 'base_quantity'),
            disposition: QuarantineDisposition::from($this->stringValue($data, 'disposition')),
            reasonCategory: ConditionChangeReason::from($this->stringValue($data, 'reason_category')),
            reason: $this->stringValue($data, 'reason'),
        );
    }

    /** @param array<string,mixed> $data */
    private function damageData(array $data): DamageDraftData
    {
        return new DamageDraftData(
            productVariantId: $this->requiredInt($data, 'product_variant_id'),
            warehouseId: $this->requiredInt($data, 'warehouse_id'),
            inventoryLotId: $this->optionalInt($data, 'inventory_lot_id'),
            serializedInventoryUnitId: $this->optionalInt($data, 'serialized_inventory_unit_id'),
            baseQuantity: $this->stringValue($data, 'base_quantity'),
            reasonCategory: ConditionChangeReason::from($this->stringValue($data, 'reason_category')),
            reason: $this->stringValue($data, 'reason'),
        );
    }

    /** @param array<string,mixed> $data */
    private function recoveryData(array $data): RecoveryDraftData
    {
        return new RecoveryDraftData(
            reversesConditionChangeId: $this->requiredInt($data, 'reverses_condition_change_id'),
            baseQuantity: $this->stringValue($data, 'base_quantity'),
            reasonCategory: ConditionChangeReason::from($this->stringValue($data, 'reason_category')),
            reason: $this->stringValue($data, 'reason'),
        );
    }

    /** @param array<string,mixed> $data */
    private function disposalData(array $data): DisposalDraftData
    {
        return new DisposalDraftData(
            productVariantId: $this->requiredInt($data, 'product_variant_id'),
            warehouseId: $this->requiredInt($data, 'warehouse_id'),
            inventoryLotId: $this->optionalInt($data, 'inventory_lot_id'),
            serializedInventoryUnitId: $this->optionalInt($data, 'serialized_inventory_unit_id'),
            baseQuantity: $this->stringValue($data, 'base_quantity'),
            reasonCategory: ConditionChangeReason::from($this->stringValue($data, 'reason_category')),
            reason: $this->stringValue($data, 'reason'),
            authorisedBy: $this->requiredInt($data, 'authorised_by'),
        );
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

        if (is_string($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            throw new LogicException('An authenticated inventory condition-change actor is required.');
        }

        return $actor;
    }

    #[\Override]
    protected function getRedirectUrl(): string
    {
        /** @var InventoryConditionChange $record */
        $record = $this->record;

        return InventoryConditionChangeResource::getUrl('view', ['record' => $record]);
    }
}
