<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MaintenanceStatus;
use App\Enums\MovementType;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\InventoryPostingService;
use App\Services\Inventory\ProductTypeGuard;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class ServiceRecordPartService
{
    public function __construct(
        private InventoryPostingService $inventoryPostingService,
        private InventoryLotService $inventoryLotService,
        private ProductTypeGuard $productTypeGuard,
    ) {}

    public function consume(
        MaintenanceTask $task,
        int $productVariantId,
        int $warehouseId,
        float $quantity,
        User $actor,
        ?int $inventoryLotId = null,
        ?int $serializedInventoryUnitId = null,
    ): ServiceRecordPart {
        Gate::forUser($actor)->authorize('consume', $task);

        if (in_array($task->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)) {
            throw new InvalidStatusTransition(sprintf(
                'Parts cannot be consumed against a %s service record.',
                $task->status->value,
            ));
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'The quantity must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use (
            $task,
            $productVariantId,
            $warehouseId,
            $quantity,
            $actor,
            $inventoryLotId,
            $serializedInventoryUnitId,
        ): ServiceRecordPart {
            $variant = ProductVariant::query()
                ->with(['product', 'unit'])
                ->lockForUpdate()
                ->findOrFail($productVariantId);

            $this->productTypeGuard->assertQuantity($variant, $quantity, $variant->unit);

            [$lot, $unit] = $this->trackingAllocation(
                $variant,
                $warehouseId,
                $quantity,
                $inventoryLotId,
                $serializedInventoryUnitId,
                false,
            );

            $part = ServiceRecordPart::query()->create([
                'maintenance_task_id' => $task->getKey(),
                'product_variant_id' => $productVariantId,
                'warehouse_id' => $warehouseId,
                'inventory_lot_id' => $lot?->getKey(),
                'serialized_inventory_unit_id' => $unit?->getKey(),
                'quantity' => $this->quantity($quantity),
                'inventory_movement_id' => null,
                'created_by' => $actor->getKey(),
            ]);

            try {
                $posting = $this->inventoryPostingService->post(
                    $this->postingCommand(
                        part: $part,
                        actor: $actor,
                        quantityDelta: '-'.$this->quantity($quantity),
                        variant: $variant,
                        lot: $lot,
                        unit: $unit,
                        reversal: false,
                    ),
                );
            } catch (DomainException) {
                $available = InventoryStock::query()
                    ->where('product_variant_id', $productVariantId)
                    ->where('warehouse_id', $warehouseId)
                    ->value('available_quantity');

                throw ValidationException::withMessages([
                    'quantity' => sprintf('Only %s units are available.', is_numeric($available) ? (string) $available : '0'),
                ]);
            }

            $part->forceFill(['inventory_movement_id' => $posting->movement->getKey()])->save();

            activity()
                ->performedOn($part)
                ->causedBy($actor)
                ->withChanges(['attributes' => $part->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record_part.consumed');

            return $part->refresh();
        });
    }

    public function reverse(ServiceRecordPart $part, User $actor): void
    {
        Gate::forUser($actor)->authorize('reverse', MaintenanceTask::class);

        DB::transaction(function () use ($part, $actor): void {
            $partKey = $part->getKey();

            if (! is_int($partKey)) {
                throw new \LogicException('Service record part identifiers must be integers.');
            }

            $locked = ServiceRecordPart::query()->lockForUpdate()->findOrFail($partKey);

            if ($locked->reversed_at !== null) {
                throw new DomainException('This consumption has already been reversed.');
            }

            $variant = ProductVariant::query()
                ->with(['product', 'unit'])
                ->lockForUpdate()
                ->findOrFail($locked->product_variant_id);

            [$lot, $unit] = $this->trackingAllocation(
                $variant,
                (int) $locked->warehouse_id,
                (float) $locked->quantity,
                $locked->inventory_lot_id,
                $locked->serialized_inventory_unit_id,
                true,
            );

            $posting = $this->inventoryPostingService->post(
                $this->postingCommand(
                    part: $locked,
                    actor: $actor,
                    quantityDelta: $this->quantity((float) $locked->quantity),
                    variant: $variant,
                    lot: $lot,
                    unit: $unit,
                    reversal: true,
                ),
            );

            $locked->update([
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'reversal_movement_id' => $posting->movement->getKey(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->withChanges(['attributes' => [
                    'reversed_at' => $locked->reversed_at,
                    'reversal_movement_id' => $posting->movement->getKey(),
                ]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record_part.reversed');
        });
    }

    /**
     * @return array{0: InventoryLot|null, 1: SerializedInventoryUnit|null}
     */
    private function trackingAllocation(
        ProductVariant $variant,
        int $warehouseId,
        float $quantity,
        ?int $inventoryLotId,
        ?int $serializedInventoryUnitId,
        bool $reversal,
    ): array {
        $lot = null;
        $unit = null;

        if ($variant->productType()?->tracksBatches() === true) {
            if ($inventoryLotId === null) {
                throw ValidationException::withMessages([
                    'inventory_lot_id' => __('admin.inventory.lot.errors.required'),
                ]);
            }

            $lot = InventoryLot::query()->lockForUpdate()->find($inventoryLotId);

            if (
                ! $lot instanceof InventoryLot
                || $lot->canonical_inventory_lot_id !== null
                || $lot->product_variant_id !== $variant->getKey()
                || ! $this->inventoryLotService->saleableBalanceForUpdate($lot, $warehouseId) instanceof InventoryLotBalance
            ) {
                throw ValidationException::withMessages([
                    'inventory_lot_id' => __('admin.inventory.lot.errors.required'),
                ]);
            }
        }

        if ($variant->productType()?->tracksSerials() === true) {
            if ($serializedInventoryUnitId === null || round($quantity, 6) !== 1.0) {
                throw ValidationException::withMessages([
                    'serialized_inventory_unit_id' => 'A serialized maintenance part requires exactly one device allocation.',
                ]);
            }

            $unit = SerializedInventoryUnit::query()->lockForUpdate()->find($serializedInventoryUnitId);

            $valid = $unit instanceof SerializedInventoryUnit
                && $unit->product_variant_id === $variant->getKey()
                && ($reversal
                    ? (
                        $unit->status === SerializedInventoryUnitStatus::Consumed
                        && $unit->stock_condition === StockCondition::Saleable
                    )
                    : (
                        $unit->status === SerializedInventoryUnitStatus::Available
                        && $unit->warehouse_id === $warehouseId
                        && $unit->stock_condition === StockCondition::Saleable
                    ));

            if (! $valid) {
                throw ValidationException::withMessages([
                    'serialized_inventory_unit_id' => 'The serialized maintenance part is not eligible for this operation.',
                ]);
            }
        }

        if (
            $lot instanceof InventoryLot
            && $unit instanceof SerializedInventoryUnit
            && $unit->inventory_lot_id !== $lot->getKey()
        ) {
            throw ValidationException::withMessages([
                'serialized_inventory_unit_id' => 'The serialized unit does not belong to the selected lot identity.',
            ]);
        }

        return [$lot, $unit];
    }

    private function postingCommand(
        ServiceRecordPart $part,
        User $actor,
        string $quantityDelta,
        ProductVariant $variant,
        ?InventoryLot $lot,
        ?SerializedInventoryUnit $unit,
        bool $reversal,
    ): InventoryPostingCommand {
        $partId = $part->getKey();
        $actorId = $actor->getKey();
        $taskId = $part->maintenance_task_id;

        if (! is_int($partId) || ! is_int($actorId)) {
            throw new \LogicException('Service record part postings require integer identifiers.');
        }

        $serializedInventoryUnitId = $unit?->getKey();
        $inventoryLotId = $lot?->getKey();
        $transactionUnitId = $variant->unit_id;

        if (! is_int($transactionUnitId)) {
            throw new \LogicException('Service record part variants require an integer base-unit identifier.');
        }

        $transactionQuantity = bccomp($quantityDelta, '0', 6) < 0
            ? bcsub('0', $quantityDelta, 6)
            : $quantityDelta;

        if (
            ($serializedInventoryUnitId !== null && ! is_int($serializedInventoryUnitId))
            || ($inventoryLotId !== null && ! is_int($inventoryLotId))
        ) {
            throw new \LogicException('Service record part postings require integer identifiers.');
        }

        return new InventoryPostingCommand(
            productVariantId: (int) $part->product_variant_id,
            warehouseId: (int) $part->warehouse_id,
            onHandBaseQuantityDelta: $quantityDelta,
            reservedBaseQuantityDelta: '0',
            damagedBaseQuantityDelta: '0',
            movementType: MovementType::ServiceConsumption,
            movementBaseQuantityDelta: $quantityDelta,
            sourceType: 'service_record_part',
            sourceId: $partId,
            actorId: $actorId,
            serializedInventoryUnitId: $serializedInventoryUnitId,
            idempotencyKey: sprintf('service-record-part:%d:%s', $partId, $reversal ? 'reverse' : 'consume'),
            balanceMode: InventoryPostingBalanceMode::RequireExisting,
            inventoryLotId: $inventoryLotId,
            sourceLineType: 'maintenance_task',
            sourceLineId: $taskId,
            transactionQuantity: $transactionQuantity,
            transactionUnitId: $transactionUnitId,
            conversionFactorSnapshot: '1.000000',
            baseQuantityDelta: $quantityDelta,
            lotOnHandBaseQuantityDelta: $lot instanceof InventoryLot ? $quantityDelta : null,
            serializedTargetStatus: $unit instanceof SerializedInventoryUnit
                ? ($reversal ? SerializedInventoryUnitStatus::Available : SerializedInventoryUnitStatus::Consumed)
                : null,
            serializedWarehouseSpecified: $unit instanceof SerializedInventoryUnit,
            serializedTargetWarehouseId: $unit instanceof SerializedInventoryUnit && $reversal
                ? (int) $part->warehouse_id
                : null,
            serializedTargetCustodyType: $unit instanceof SerializedInventoryUnit
                ? ($reversal ? SerializedCustodyType::Warehouse : SerializedCustodyType::Maintenance)
                : null,
            serializedTargetCustodyReferenceType: $unit instanceof SerializedInventoryUnit
                ? ($reversal ? 'warehouse' : 'maintenance_task')
                : null,
            serializedTargetCustodyReferenceId: $unit instanceof SerializedInventoryUnit
                ? ($reversal ? (int) $part->warehouse_id : $taskId)
                : null,
        );
    }

    private function quantity(float $quantity): string
    {
        return number_format($quantity, 6, '.', '');
    }
}
