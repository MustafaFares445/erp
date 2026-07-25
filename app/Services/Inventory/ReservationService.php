<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ReservationStatus;
use App\Models\InventoryStock;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ReservationService
{
    public function __construct(
        private AuditLogger $auditLogger,
        private InventoryBalanceService $inventoryBalanceService,
        private InventoryAlertService $inventoryAlertService,
    ) {}

    /** @throws DomainException */
    public function release(StockReservation $reservation, User $actor): void
    {
        DB::transaction(function () use ($reservation, $actor): void {
            /** @var StockReservation $locked */
            $locked = StockReservation::query()->lockForUpdate()->findOrFail($reservation->getKey());

            if (! $locked->isReleasable()) {
                throw new DomainException(__('admin.inventory.reservation.errors.not_releasable'));
            }

            /** @var InventoryStock $stock */
            $stock = InventoryStock::query()
                ->where('product_variant_id', $locked->product_variant_id)
                ->where('warehouse_id', $locked->warehouse_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $stock->reserved_quantity < (float) $locked->quantity) {
                throw new DomainException(__('admin.inventory.reservation.errors.invalid_balance'));
            }

            $reservedBefore = (float) $stock->reserved_quantity;
            $stock = $this->inventoryBalanceService->releaseReservation($stock, (float) $locked->quantity);
            $this->inventoryAlertService->syncStock($stock);

            $locked->forceFill([
                'status' => ReservationStatus::Released,
                'updated_by' => $actor->getKey(),
            ])->saveQuietly();

            $this->auditLogger->log(
                action: 'inventory.reservation.released',
                entity: $locked,
                oldValues: ['status' => ReservationStatus::Active->value, 'reserved_quantity' => $reservedBefore],
                newValues: ['status' => ReservationStatus::Released->value, 'reserved_quantity' => (float) $stock->reserved_quantity],
                actor: $actor,
                sourceChannel: 'dashboard',
            );
        }, attempts: 5);
    }
}
