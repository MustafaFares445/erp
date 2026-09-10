<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Accounting\PurchaseOrderDraftBillService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the cross-module side effects of a Purchase Order becoming Accepted.
 *
 * Physical stock remains entirely owned by Inventory receipt completion. This
 * orchestrator creates only the inbound allocation aggregate, supplier-cost
 * commercial snapshot, and AP Draft Bill. Each collaborator is idempotent so
 * retrying cannot duplicate downstream records.
 *
 * Supplier-confirmation activation is deliberately not guessed here. The
 * remediation plan requires an explicit Supplier/PO flag before that workflow
 * becomes automatic; until that prerequisite exists, confirmations remain the
 * existing explicit/manual workflow.
 */
final readonly class PurchaseOrderAcceptanceOrchestrator
{
    public function __construct(
        private PurchaseInboundService $inbounds,
        private SupplierCostWritebackService $supplierCosts,
        private PurchaseOrderDraftBillService $draftBills,
    ) {}

    public function handle(User $actor, PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($actor, $order): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()
                ->with(['lines.productVariant'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            if ($locked->status !== PurchaseOrderStatus::Accepted) {
                throw new DomainException('Purchase order acceptance side effects require an accepted purchase order.');
            }

            // Logistics allocation metadata only: no InventoryOperation,
            // InventoryMovement, or stock mutation is created here.
            $this->inbounds->ensureForAccepted($locked);

            // Commercial ownership: update the supplier's accepted cost signal.
            $this->supplierCosts->apply($locked);

            // Accounting ownership: create one PO-linked Draft Bill for review.
            $this->draftBills->ensureForAccepted($actor, $locked);

            return $locked->refresh();
        });
    }
}
