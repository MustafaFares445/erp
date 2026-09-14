<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\SalesProcurementRequirement;

final readonly class OrderWorkflowService
{
    public function __construct(
        private OrderFulfillmentQuantityService $quantities,
        private OrderNextActionResolver $nextActions,
    ) {}

    public function project(Order $order): OrderWorkflowProjection
    {
        $totals = $this->quantities->totals($order);
        $requirements = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled'])
            ->get(['required_base_quantity', 'fulfilled_base_quantity', 'status']);
        $procurementOutstanding = round((float) $requirements->sum(
            fn (SalesProcurementRequirement $requirement): float => (float) $requirement->outstandingBaseQuantity(),
        ), 6);

        $invoices = $order->invoices()->get(['total_amount', 'amount_paid', 'credited_amount', 'issued_at']);
        $invoiceTotal = round($this->floatValue($invoices->sum('total_amount')), 2);
        $paid = round($this->floatValue($invoices->sum('amount_paid')), 2);
        $credited = round($this->floatValue($invoices->sum('credited_amount')), 2);
        $outstanding = max(0.0, round($invoiceTotal - $paid - $credited, 2));

        $facts = [
            ...$totals,
            'procurement_outstanding' => $procurementOutstanding,
            'outstanding_receivable' => $outstanding,
        ];
        $next = $this->nextActions->resolve($order, $facts);
        [$blockerCode, $blockerMessage] = $this->blocker($order, $facts);
        $milestone = $this->milestone($order, $facts);
        $effectiveOrdered = max(0.0, $totals['ordered'] - $totals['short_closed']);
        $progress = $effectiveOrdered <= 0.000001
            ? 100.0
            : min(100.0, round(($totals['arrived'] / $effectiveOrdered) * 100, 2));

        return new OrderWorkflowProjection(
            commercialStatus: $order->status,
            businessMilestone: $milestone,
            fulfillmentProgressPercent: $progress,
            requestedBase: $totals['ordered'],
            plannedBase: $totals['planned'],
            readyBase: $totals['ready'],
            dispatchedBase: $totals['dispatched'],
            arrivedBase: $totals['arrived'],
            returnedBase: $totals['returned'],
            remainingBase: $totals['remaining'],
            procurementOutstandingBase: $procurementOutstanding,
            invoiceTotal: $invoiceTotal,
            paidTotal: $paid,
            creditedTotal: $credited,
            outstandingReceivable: $outstanding,
            blockerCode: $blockerCode,
            blockerMessage: $blockerMessage,
            nextActionOwner: $next['owner'],
            nextActionLabel: $next['label'],
            nextActionRoute: $next['route'],
        );
    }

    /** @param array<string, float> $facts */
    private function milestone(Order $order, array $facts): string
    {
        if ($order->status === OrderStatus::Cancelled) {
            return 'Cancelled';
        }
        if ($order->status === OrderStatus::Closed) {
            return 'Closed';
        }
        if ($order->status === OrderStatus::Draft) {
            return 'Draft';
        }
        if ($order->status === OrderStatus::Confirmed) {
            return 'Awaiting Release';
        }
        if (($facts['procurement_outstanding'] ?? 0.0) > 0.000001) {
            return 'Supply Blocked';
        }
        if (($facts['remaining'] ?? 0.0) > 0.000001 && ($facts['planned'] ?? 0.0) <= 0.000001) {
            return 'Awaiting Logistics Allocation';
        }
        if (($facts['remaining'] ?? 0.0) > 0.000001) {
            return 'Partially Allocated';
        }
        if (($facts['ready'] ?? 0.0) > 0.000001) {
            return 'Ready to Dispatch';
        }
        if (($facts['dispatched'] ?? 0.0) > ($facts['arrived'] ?? 0.0) + 0.000001) {
            return 'In Transit';
        }
        if (($facts['dispatched'] ?? 0.0) > ($facts['invoiced'] ?? 0.0) + 0.000001) {
            return 'Invoice Pending';
        }
        if (($facts['outstanding_receivable'] ?? 0.0) > 0.009) {
            return 'Payment Pending';
        }
        if (($facts['arrived'] ?? 0.0) > 0.000001) {
            return 'Delivered';
        }

        return 'Released';
    }

    /**
     * @param  array<string, float>  $facts
     * @return array{0: string|null, 1: string|null}
     */
    private function blocker(Order $order, array $facts): array
    {
        if ($order->status === OrderStatus::Draft) {
            return ['commercial_not_confirmed', 'The commercial order must be confirmed before release.'];
        }
        if ($order->status === OrderStatus::Confirmed) {
            return ['not_released', 'The order is confirmed but has not been released to Logistics.'];
        }
        if (($facts['procurement_outstanding'] ?? 0.0) > 0.000001) {
            return ['procurement_open', 'Remaining customer demand is waiting for purchased stock.'];
        }
        if (($facts['remaining'] ?? 0.0) > 0.000001) {
            return ['awaiting_logistics_allocation', 'Logistics must allocate the remaining released demand.'];
        }
        if (($facts['ready'] ?? 0.0) > 0.000001) {
            return ['delivery_waiting_stock', 'Prepared goods are waiting to be dispatched.'];
        }
        if (($facts['dispatched'] ?? 0.0) > ($facts['arrived'] ?? 0.0) + 0.000001) {
            return ['shipment_in_transit', 'At least one dispatched shipment is still in transit.'];
        }
        if (($facts['dispatched'] ?? 0.0) > ($facts['invoiced'] ?? 0.0) + 0.000001) {
            return ['invoice_pending', 'Dispatched quantity is waiting for invoice coverage.'];
        }
        if (($facts['outstanding_receivable'] ?? 0.0) > 0.009) {
            return ['payment_pending', 'Issued invoice value remains unpaid.'];
        }

        return [null, null];
    }

    private function floatValue(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new \LogicException('An invoice amount must be numeric.');
        }

        return (float) $value;
    }
}
