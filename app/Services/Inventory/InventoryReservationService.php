<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\InventoryPostingCommand;
use App\Enums\InventoryPermission;
use App\Enums\InventoryPostingBalanceMode;
use App\Enums\MovementType;
use App\Enums\ReservationStatus;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class InventoryReservationService
{
    public function __construct(
        private InventoryPostingService $inventoryPostingService,
        private InventoryLotService $inventoryLotService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    /**
     * @param  Collection<int, InventoryOperationLine>  $lines
     */
    public function reserveOperation(
        InventoryOperation $operation,
        Collection $lines,
        int $warehouseId,
        ?User $actor,
    ): void {
        DB::transaction(function () use ($operation, $lines, $warehouseId, $actor): void {
            $commands = [];

            foreach ($lines->sortBy('id') as $line) {
                $existing = InventoryReservation::query()
                    ->where('source_type', 'inventory_operation')
                    ->where('source_id', $this->operationId($operation))
                    ->where('source_line_type', 'inventory_operation_line')
                    ->where('source_line_id', $this->lineId($line))
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof InventoryReservation) {
                    if ($existing->status === ReservationStatus::Active) {
                        continue;
                    }

                    throw new DomainException('An inventory operation line cannot create a second reservation after its first reservation was resolved.');
                }

                $baseQuantity = $this->positiveBaseQuantity((string) $line->base_quantity);
                $reservation = InventoryReservation::query()->forceCreate([
                    'product_variant_id' => $line->product_variant_id,
                    'warehouse_id' => $warehouseId,
                    'source_type' => 'inventory_operation',
                    'source_id' => $this->operationId($operation),
                    'source_line_type' => 'inventory_operation_line',
                    'source_line_id' => $this->lineId($line),
                    'base_quantity' => $baseQuantity,
                    'status' => ReservationStatus::Active,
                    'created_by' => $this->actorId($actor),
                    'updated_by' => $this->actorId($actor),
                ]);

                $allocation = $reservation->allocations()->create([
                    'inventory_lot_id' => $line->inventory_lot_id,
                    'serialized_inventory_unit_id' => $line->serialized_inventory_unit_id,
                    'base_quantity' => $baseQuantity,
                ]);

                $this->reserveLotAllocation($allocation, $actor);
                $commands[] = $this->reservationPostingCommand($reservation, $baseQuantity, 'activate', $actor);
            }

            if ($commands === []) {
                return;
            }

            foreach ($this->inventoryPostingService->postMany($commands) as $posting) {
                $this->inventoryAlertService->syncStock($posting->stock);
            }
        }, attempts: 5);
    }

    public function consumeOperation(InventoryOperation $operation, ?User $actor): void
    {
        DB::transaction(function () use ($operation, $actor): void {
            $reservations = $this->activeOperationReservations($operation);

            foreach ($reservations as $reservation) {
                $reservation->forceFill([
                    'status' => ReservationStatus::Consumed,
                    'consumed_at' => now(),
                    'updated_by' => $this->actorId($actor),
                ])->save();
            }
        }, attempts: 5);
    }

    public function releaseOperation(InventoryOperation $operation, ?User $actor): void
    {
        DB::transaction(function () use ($operation, $actor): void {
            $this->releaseMany(
                $this->activeOperationReservations($operation),
                ReservationStatus::Released,
                $actor,
            );
        }, attempts: 5);
    }

    public function release(InventoryReservation $reservation, ?User $actor = null): void
    {
        DB::transaction(function () use ($reservation, $actor): void {
            $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            if (! $locked->isActive()) {
                throw new DomainException(__('admin.inventory.reservation.errors.not_releasable'));
            }

            $this->releaseMany(new Collection([$locked]), ReservationStatus::Released, $actor);
        }, attempts: 5);
    }

    public function expire(InventoryReservation $reservation, ?User $actor = null): void
    {
        DB::transaction(function () use ($reservation, $actor): void {
            $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            if (! $locked->isActive()) {
                return;
            }

            $this->releaseMany(new Collection([$locked]), ReservationStatus::Expired, $actor);
        }, attempts: 5);
    }

    /**
     * @param  Collection<int, InventoryReservation>  $reservations
     */
    private function releaseMany(Collection $reservations, ReservationStatus $status, ?User $actor): void
    {
        if ($reservations->isEmpty()) {
            return;
        }

        $commands = [];

        foreach ($reservations as $reservation) {
            $baseQuantity = $this->positiveBaseQuantity((string) $reservation->base_quantity);

            foreach ($reservation->allocations()->orderBy('id')->lockForUpdate()->get() as $allocation) {
                $this->releaseLotAllocation($allocation);
            }

            $commands[] = $this->reservationPostingCommand(
                $reservation,
                bcsub('0', $baseQuantity, 6),
                $status === ReservationStatus::Expired ? 'expire' : 'release',
                $actor,
            );
        }

        foreach ($this->inventoryPostingService->postMany($commands) as $posting) {
            $this->inventoryAlertService->syncStock($posting->stock);
        }

        foreach ($reservations as $reservation) {
            $reservation->forceFill([
                'status' => $status,
                'released_at' => now(),
                'updated_by' => $this->actorId($actor),
            ])->save();
        }
    }

    /** @return Collection<int, InventoryReservation> */
    private function activeOperationReservations(InventoryOperation $operation): Collection
    {
        return InventoryReservation::query()
            ->where('source_type', 'inventory_operation')
            ->where('source_id', $this->operationId($operation))
            ->where('status', ReservationStatus::Active->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function reserveLotAllocation(InventoryReservationAllocation $allocation, ?User $actor): void
    {
        if ($allocation->inventory_lot_id === null) {
            return;
        }

        $lot = InventoryLot::query()->lockForUpdate()->findOrFail($allocation->inventory_lot_id);

        $this->inventoryLotService->reserveQuantity(
            $lot,
            $this->positiveBaseQuantity((string) $allocation->base_quantity),
            $actor,
            $actor?->can(InventoryPermission::ExpiredStockOverride->value) === true,
        );
    }

    private function releaseLotAllocation(InventoryReservationAllocation $allocation): void
    {
        if ($allocation->inventory_lot_id === null) {
            return;
        }

        $lot = InventoryLot::query()->lockForUpdate()->findOrFail($allocation->inventory_lot_id);

        $this->inventoryLotService->releaseQuantity(
            $lot,
            $this->positiveBaseQuantity((string) $allocation->base_quantity),
        );
    }

    private function reservationPostingCommand(
        InventoryReservation $reservation,
        string $reservedDelta,
        string $action,
        ?User $actor,
    ): InventoryPostingCommand {
        return new InventoryPostingCommand(
            productVariantId: (int) $reservation->product_variant_id,
            warehouseId: (int) $reservation->warehouse_id,
            onHandBaseQuantityDelta: '0',
            reservedBaseQuantityDelta: $reservedDelta,
            damagedBaseQuantityDelta: '0',
            movementType: MovementType::Reservation,
            movementBaseQuantityDelta: '0.000000',
            sourceType: 'inventory_reservation',
            sourceId: $this->reservationId($reservation),
            actorId: $this->actorId($actor),
            notes: 'Inventory reservation '.$action,
            idempotencyKey: sprintf('inventory-reservation:%d:%s', $this->reservationId($reservation), $action),
            balanceMode: InventoryPostingBalanceMode::RequireExisting,
        );
    }

    /** @return numeric-string */
    private function positiveBaseQuantity(string $quantity): string
    {
        if (! is_numeric($quantity) || preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/D', $quantity) !== 1) {
            throw new DomainException('Inventory reservation quantities must be exact base-UOM decimal strings with at most six places.');
        }

        $normalized = bcadd($quantity, '0', 6);

        if (bccomp($normalized, '0', 6) <= 0) {
            throw new DomainException('Inventory reservation quantities must be positive.');
        }

        return $normalized;
    }

    private function operationId(InventoryOperation $operation): int
    {
        $id = $operation->getKey();

        if (! is_int($id)) {
            throw new \LogicException('Inventory operations must use integer identifiers.');
        }

        return $id;
    }

    private function lineId(InventoryOperationLine $line): int
    {
        $id = $line->getKey();

        if (! is_int($id)) {
            throw new \LogicException('Inventory operation lines must use integer identifiers.');
        }

        return $id;
    }

    private function reservationId(InventoryReservation $reservation): int
    {
        $id = $reservation->getKey();

        if (! is_int($id)) {
            throw new \LogicException('Inventory reservations must use integer identifiers.');
        }

        return $id;
    }

    private function actorId(?User $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        $id = $actor->getKey();

        if (! is_int($id)) {
            throw new \LogicException('Inventory reservation actors must use integer identifiers.');
        }

        return $id;
    }
}
