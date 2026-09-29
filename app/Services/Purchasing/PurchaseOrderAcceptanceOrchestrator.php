<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Events\PurchaseOrderAccepted;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Accounting\PurchaseOrderDraftBillService;
use App\Services\Supply\PurchaseReplenishmentCoverageService;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class PurchaseOrderAcceptanceOrchestrator
{
    public function __construct(
        private PurchaseInboundService $inbounds,
        private SupplierConfirmationService $confirmations,
        private SupplierCostWritebackService $supplierCosts,
        private PurchaseOrderDraftBillService $draftBills,
        private PurchaseReplenishmentCoverageService $replenishmentCoverage,
    ) {}

    public function handle(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->with(['supplier', 'lines.productVariant'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            if ($locked->status !== PurchaseOrderStatus::Accepted) {
                throw new DomainException('Purchase order activation requires an accepted purchase order.');
            }

            if ($locked->sent_at === null) {
                throw new DomainException('Send the Purchase Order to the supplier before activating downstream execution.');
            }

            if ($locked->supplier_confirmation_required === null) {
                $locked->forceFill([
                    'supplier_confirmation_required' => (bool) $locked->supplier->requires_confirmation,
                    'updated_by' => $actor->getKey(),
                ])->save();
            }

            $this->inbounds->ensureForAccepted($locked);

            if ($locked->supplier_confirmation_required && ! $locked->confirmations()->exists()) {
                $this->confirmations->recordPurchaseOrder(
                    $actor,
                    $locked,
                    'Automatically requested when the purchase order was accepted.',
                );
            }

            $this->supplierCosts->apply($locked);
            $bill = $this->draftBills->ensureForAccepted($actor, $locked);

            // Allocation remains Inventory-owned. This call is intentionally
            // idempotent/no-op until an accepted inbound line has a warehouse;
            // PurchaseInboundService calls the same service again on allocation.
            $this->replenishmentCoverage->syncForOrder($locked);

            PurchaseOrderAccepted::dispatch($locked, $bill);

            return $locked->refresh();
        });
    }
}
