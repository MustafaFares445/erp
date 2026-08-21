<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\MaintenanceStatus;
use App\Enums\MovementType;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\MaintenanceTask;
use App\Models\ServiceRecordPart;
use App\Models\User;
use App\Services\Inventory\InventoryBalanceService;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Spare-parts consumption and reversal (FR-080–088,
 * contracts/maintenance-lifecycle.md §4, research.md §2). The only place
 * this module writes outside its own domain — always through
 * {@see InventoryBalanceService}, never a direct `inventory_stocks` write
 * (Principle III).
 */
final readonly class ServiceRecordPartService
{
    public function __construct(private InventoryBalanceService $balanceService) {}

    public function consume(MaintenanceTask $task, int $productVariantId, int $warehouseId, float $quantity, User $actor): ServiceRecordPart
    {
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

        return DB::transaction(function () use ($task, $productVariantId, $warehouseId, $quantity, $actor): ServiceRecordPart {
            try {
                // $quantity > 0 is already guaranteed above, so a DomainException here can only
                // mean insufficient available stock — never App\Services\Inventory\
                // InventoryBalanceService::requirePositive()'s non-positive-quantity rejection.
                $this->balanceService->transferOut($productVariantId, $warehouseId, $quantity);
            } catch (DomainException) {
                $available = InventoryStock::query()
                    ->where('product_variant_id', $productVariantId)
                    ->where('warehouse_id', $warehouseId)
                    ->value('available_quantity');

                throw ValidationException::withMessages([
                    'quantity' => sprintf('Only %s units are available.', is_numeric($available) ? (string) $available : '0'),
                ]);
            }

            $movement = InventoryMovement::query()->forceCreate([
                'product_variant_id' => $productVariantId,
                'warehouse_id' => $warehouseId,
                'movement_type' => MovementType::ServiceConsumption,
                'quantity' => -$quantity,
                'source_type' => 'service_record_part',
                'status' => 'confirmed',
                'created_by' => $actor->getKey(),
            ]);

            $part = ServiceRecordPart::query()->create([
                'maintenance_task_id' => $task->getKey(),
                'product_variant_id' => $productVariantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'inventory_movement_id' => $movement->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            $movement->forceFill(['source_id' => $part->getKey()])->save();

            activity()
                ->performedOn($part)
                ->causedBy($actor)
                ->withChanges(['attributes' => $part->getAttributes()])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record_part.consumed');

            return $part;
        });
    }

    /**
     * Always the full original quantity — there is no partial-reversal
     * parameter (clarification, 2026-08-13). MUST NOT edit or delete the
     * original record (FR-086); only its `reversed_*` columns are set.
     */
    public function reverse(ServiceRecordPart $part, User $actor): void
    {
        Gate::forUser($actor)->authorize('reverse', MaintenanceTask::class);

        if ($part->reversed_at !== null) {
            throw new DomainException('This consumption has already been reversed.');
        }

        DB::transaction(function () use ($part, $actor): void {
            $this->balanceService->transferIn($part->product_variant_id, $part->warehouse_id, (float) $part->quantity);

            $reversalMovement = InventoryMovement::query()->forceCreate([
                'product_variant_id' => $part->product_variant_id,
                'warehouse_id' => $part->warehouse_id,
                'movement_type' => MovementType::ServiceConsumption,
                'quantity' => $part->quantity,
                'source_type' => 'service_record_part',
                'source_id' => $part->getKey(),
                'status' => 'confirmed',
                'created_by' => $actor->getKey(),
            ]);

            $part->update([
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'reversal_movement_id' => $reversalMovement->getKey(),
            ]);

            activity()
                ->performedOn($part)
                ->causedBy($actor)
                ->withChanges(['attributes' => ['reversed_at' => $part->reversed_at, 'reversal_movement_id' => $reversalMovement->getKey()]])
                ->withProperties(['source_channel' => 'dashboard', 'ip_address' => request()->ip()])
                ->log('support.service_record_part.reversed');
        });
    }
}
