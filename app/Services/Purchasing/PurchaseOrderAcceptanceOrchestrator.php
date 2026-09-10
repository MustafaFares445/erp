<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Events\PurchaseOrderAccepted;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Accounting\PurchaseOrderDraftBillService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the cross-module side effects of a Purchase Order becoming Accepted.
 *
 * Physical stock remains entirely owned by Inventory receipt completion. This
 * orchestrator creates only the inbound allocation aggregate, optional supplier
 * confirmation workflow, supplier-cost commercial snapshot, and AP Draft Bill.
 * Each collaborator is idempotent so retrying cannot duplicate downstream
 * records.
 */
final readonly class PurchaseOrderAcceptanceOrchestrator
{
    public function __construct(
        private PurchaseInboundService $inbounds,
        private SupplierConfirmationService $confirmations,
        private SupplierCostWritebackService $supplierCosts,
        private PurchaseOrderDraftBillService $draftBills,
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
                throw new DomainException('Purchase order acceptance side effects require an accepted purchase order.');
            }

            // Logistics allocation metadata only: no InventoryOperation,
            // InventoryMovement, or stock mutation is created here.
            $this->inbounds->ensureForAccepted($locked);

            // Confirmation is an explicit supplier-level opt-in. The PO row lock
            // serializes retries, so a pre-existing manual or automatic request
            // is reused rather than creating another confirmation aggregate.
            if ($locked->supplier->requires_confirmation && ! $locked->confirmations()->exists()) {
                $this->confirmations->record(
                    $actor,
                    $locked,
                    $locked->supplier_id,
                    'Automatically requested when the purchase order was accepted.',
                );
            }

            // Commercial ownership: update the supplier's accepted cost signal.
            $this->supplierCosts->apply($locked);

            // Accounting ownership: create one PO-linked Draft Bill for review.
            $bill = $this->draftBills->ensureForAccepted($actor, $locked);

            // The event implements ShouldDispatchAfterCommit. Workflow users
            // therefore hear about documents only after this whole transaction
            // (including the caller's outer approval transaction) commits.
            PurchaseOrderAccepted::dispatch($locked, $bill);

            return $locked->refresh();
        });
    }
}
