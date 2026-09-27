<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\BillStatus;
use App\Enums\OperationStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Models\PurchaseOrder;
use App\Services\Inventory\LogisticsInboundProjectionService;

final readonly class PurchaseOrderWorkflowService
{
    private const int SCALE = 6;

    public function __construct(
        private PurchaseOrderSupplierCommitmentService $commitments,
        private LogisticsInboundProjectionService $inboundProjection,
    ) {}

    public function project(PurchaseOrder $order): PurchaseOrderWorkflowData
    {
        $order->loadMissing([
            'supplier',
            'lines.purchaseInboundLine.allocations',
            'purchaseInbound.lines.allocations.warehouse',
            'purchaseInbound.lines.purchaseOrderLine.productVariant.product',
            'receipts.lines',
            'confirmations.items',
            'bills.paymentAllocations.supplierPayment',
        ]);

        $ordered = '0.000000';
        $confirmed = '0.000000';
        $backordered = '0.000000';
        $unavailable = '0.000000';
        $allocated = '0.000000';
        $received = '0.000000';

        foreach ($order->lines as $line) {
            $quantities = $this->commitments->quantities($line);
            $ordered = bcadd($ordered, $quantities['ordered'], self::SCALE);
            $confirmed = bcadd($confirmed, $quantities['confirmed'], self::SCALE);
            $backordered = bcadd($backordered, $quantities['backordered'], self::SCALE);
            $unavailable = bcadd($unavailable, $quantities['unavailable'], self::SCALE);
            $allocated = bcadd($allocated, $quantities['allocated'], self::SCALE);
            $received = bcadd($received, $line->received_base_quantity ?? '0.000000', self::SCALE);
        }

        $inProgress = $this->openReceiptQuantity($order);
        $remainingConfirmed = $this->nonNegativeSubtract($confirmed, $received);
        [$billTotal, $paidTotal, $outstandingTotal, $financialState] = $this->financial($order);
        $supplierState = $this->supplierState($order, $confirmed, $backordered, $unavailable);
        $logisticsState = $order->purchaseInbound === null
            ? 'Not activated'
            : $this->inboundProjection->project($order->purchaseInbound)->businessState;

        [$businessState, $blocker, $nextOwner, $nextAction] = $this->next(
            $order,
            $confirmed,
            $backordered,
            $unavailable,
            $allocated,
            $inProgress,
            $received,
            $remainingConfirmed,
            $outstandingTotal,
            $financialState,
            $supplierState,
            $logisticsState,
        );

        return new PurchaseOrderWorkflowData(
            businessState: $businessState,
            supplierState: $supplierState,
            logisticsState: $logisticsState,
            financialState: $financialState,
            orderedBaseQuantity: $ordered,
            confirmedBaseQuantity: $confirmed,
            backorderedBaseQuantity: $backordered,
            unavailableBaseQuantity: $unavailable,
            allocatedBaseQuantity: $allocated,
            receiptInProgressBaseQuantity: $inProgress,
            receivedBaseQuantity: $received,
            remainingConfirmedBaseQuantity: $remainingConfirmed,
            billTotal: $billTotal,
            paidTotal: $paidTotal,
            outstandingTotal: $outstandingTotal,
            blocker: $blocker,
            nextOwner: $nextOwner,
            nextAction: $nextAction,
        );
    }

    /** @return array{0:numeric-string,1:numeric-string,2:numeric-string,3:string} */
    private function financial(PurchaseOrder $order): array
    {
        $total = '0.00';
        $paid = '0.00';
        $states = [];

        foreach ($order->bills as $bill) {
            if ($bill->status === BillStatus::Cancelled) {
                continue;
            }

            $total = bcadd($total, $this->numericString($bill->grandTotal()), 2);
            $paid = bcadd($paid, $this->numericString($bill->paidAmount()), 2);
            $states[$bill->status->value] = true;
        }

        $outstanding = bcsub($total, $paid, 2);
        if (bccomp($outstanding, '0.00', 2) === -1) {
            $outstanding = '0.00';
        }

        $state = match (true) {
            $states === [] => 'No accounting bill',
            isset($states[BillStatus::Draft->value]) => 'Draft bill',
            bccomp($outstanding, '0.00', 2) === 0 => 'Paid',
            bccomp($paid, '0.00', 2) === 1 => 'Partially paid',
            default => 'Approved / unpaid',
        };

        return [$total, $paid, $outstanding, $state];
    }

    /**
     * @param  numeric-string  $confirmed
     * @param  numeric-string  $backordered
     * @param  numeric-string  $unavailable
     */
    private function supplierState(
        PurchaseOrder $order,
        string $confirmed,
        string $backordered,
        string $unavailable,
    ): string {
        $required = $order->supplier_confirmation_required
            ?? (bool) $order->supplier->requires_confirmation;

        if (! $required) {
            return $order->sent_at === null
                ? 'Not sent · confirmation not required'
                : 'Sent · confirmation not required';
        }

        if ($order->sent_at === null) {
            return 'Not sent · confirmation required';
        }

        $latest = $order->confirmations->sortByDesc('id')->first();

        if ($latest === null || $latest->confirmation_status === SupplierConfirmationStatus::Pending) {
            return 'Awaiting supplier response';
        }

        if (bccomp($unavailable, '0.000000', self::SCALE) === 1) {
            return 'Supplier rejected quantity';
        }

        if (bccomp($backordered, '0.000000', self::SCALE) === 1) {
            return 'Partially confirmed / backordered';
        }

        return bccomp($confirmed, '0.000000', self::SCALE) === 1
            ? 'Confirmed'
            : $latest->confirmation_status->label();
    }

    /**
     * @param  numeric-string  $confirmed
     * @param  numeric-string  $backordered
     * @param  numeric-string  $unavailable
     * @param  numeric-string  $allocated
     * @param  numeric-string  $inProgress
     * @param  numeric-string  $received
     * @param  numeric-string  $remainingConfirmed
     * @param  numeric-string  $outstanding
     * @return array{0:string,1:?string,2:string,3:string}
     */
    private function next(
        PurchaseOrder $order,
        string $confirmed,
        string $backordered,
        string $unavailable,
        string $allocated,
        string $inProgress,
        string $received,
        string $remainingConfirmed,
        string $outstanding,
        string $financialState,
        string $supplierState,
        string $logisticsState,
    ): array {
        if ($order->status === PurchaseOrderStatus::Draft) {
            $blocker = $order->rejection_reason !== null ? 'Returned for revision' : null;

            return ['Draft', $blocker, 'Purchasing', 'Complete commercial details and submit'];
        }

        if ($order->status === PurchaseOrderStatus::PendingApproval) {
            return ['Approval required', 'Waiting for approval', 'Purchasing Manager', 'Approve or return for revision'];
        }

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return ['Cancelled', null, 'None', 'No further purchasing action'];
        }

        if ($order->status === PurchaseOrderStatus::Closed) {
            return ['Short closed', null, 'None', 'Outstanding commitment was abandoned'];
        }

        if ($order->status === PurchaseOrderStatus::Received) {
            if ($financialState === 'No accounting bill') {
                return ['Accounting exception', 'No Accounting Bill exists for this received Purchase Order', 'Accounting', 'Create or reconcile the supplier bill'];
            }

            if ($financialState === 'Draft bill') {
                return ['Accounting review', 'Supplier Bill is still a draft', 'Accounting', 'Review and approve supplier bill'];
            }

            if (bccomp($outstanding, '0.00', 2) === 1) {
                return ['Payment pending', 'Supplier payable remains open', 'Accounting', 'Settle the outstanding supplier payable'];
            }

            return ['Procurement complete', null, 'None', 'Completed'];
        }

        if ($order->sent_at === null) {
            return ['Ready to send', 'Purchase Order has not been sent to the supplier', 'Purchasing', 'Send Purchase Order to supplier'];
        }

        if ($supplierState === 'Awaiting supplier response') {
            return ['Awaiting supplier confirmation', 'Supplier response required', 'Purchasing', 'Record supplier response'];
        }

        if (bccomp($unavailable, '0.000000', self::SCALE) === 1) {
            return ['Supplier exception', 'Supplier rejected requested quantity', 'Purchasing', 'Resolve rejection or source another supplier'];
        }

        if (bccomp($confirmed, '0.000000', self::SCALE) === 0) {
            return ['Supplier commitment unavailable', 'No confirmed quantity available', 'Purchasing', 'Review supplier commitment'];
        }

        $allocatable = $this->nonNegativeSubtract($confirmed, $allocated);

        if (bccomp($allocatable, '0.000000', self::SCALE) === 1) {
            return ['Awaiting warehouse allocation', 'Confirmed quantity is not fully allocated', 'Inventory', 'Allocate destination warehouse quantities'];
        }

        if (bccomp($inProgress, '0.000000', self::SCALE) === 1) {
            return ['Receipt in progress', 'Inventory receipt is still open', 'Inventory', 'Complete the open receipt'];
        }

        if (bccomp($remainingConfirmed, '0.000000', self::SCALE) === 1) {
            return [$logisticsState, 'Confirmed quantity remains to be received', 'Inventory', 'Receive allocated goods'];
        }

        if (bccomp($backordered, '0.000000', self::SCALE) === 1) {
            return ['Backorder follow-up', 'Supplier backorder remains', 'Purchasing', 'Request the next supplier commitment'];
        }

        if (bccomp($received, '0.000000', self::SCALE) === 1 && bccomp($outstanding, '0.00', 2) === 1) {
            return ['Accounting follow-up', 'Supplier payable remains open', 'Accounting', 'Review supplier bill and payment'];
        }

        return ['Accepted', null, 'Purchasing', 'Review purchase order'];
    }

    /** @return numeric-string */
    private function openReceiptQuantity(PurchaseOrder $order): string
    {
        $total = '0.000000';

        foreach ($order->receipts as $receipt) {
            if (in_array($receipt->stage, [OperationStage::Done, OperationStage::Canceled], true)) {
                continue;
            }

            foreach ($receipt->lines as $line) {
                if ($line->base_quantity !== null) {
                    $total = bcadd($total, $line->base_quantity, self::SCALE);
                }
            }
        }

        return $total;
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function nonNegativeSubtract(string $left, string $right): string
    {
        $result = bcsub($left, $right, self::SCALE);

        return bccomp($result, '0.000000', self::SCALE) === -1 ? '0.000000' : $result;
    }

    /** @return numeric-string */
    private function numericString(mixed $value): string
    {
        if (is_string($value) && is_numeric($value)) {
            return $value;
        }

        throw new \LogicException('A purchasing workflow quantity must be numeric.');
    }
}
