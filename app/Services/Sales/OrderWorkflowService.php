<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderWorkflowProjection;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\SalesProcurementRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final readonly class OrderWorkflowService
{
    public function __construct(
        private OrderFulfillmentQuantityService $quantities,
        private OrderFinancialProjectionService $financials,
        private OrderNextActionResolver $nextActions,
    ) {}

    public function project(Order $order): OrderWorkflowProjection
    {
        $totals = $this->quantities->totals($order);
        $requirements = $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->get(['required_base_quantity', 'fulfilled_base_quantity', 'status']);
        $procurementOutstanding = round((float) $requirements->sum(
            fn (SalesProcurementRequirement $requirement): float => (float) $requirement->outstandingBaseQuantity(),
        ), 6);

        $financial = $this->financials->project($order);
        $effectiveOrdered = max(0.0, $totals['ordered'] - $totals['short_closed']);
        $fullyInvoiced = $effectiveOrdered <= $totals['invoiced'] + 0.000001;
        $autoCloseDue = $order->auto_close_due_at !== null && $order->auto_close_due_at->isPast();

        $facts = [
            ...$totals,
            'procurement_outstanding' => $procurementOutstanding,
            'outstanding_receivable' => $financial->invoiceOutstandingAmount,
            'draft_invoice_count' => (float) $financial->draftInvoiceCount,
            'fully_invoiced' => $fullyInvoiced ? 1.0 : 0.0,
            'financially_settled' => $financial->financiallySettled ? 1.0 : 0.0,
            'auto_close_due' => $autoCloseDue ? 1.0 : 0.0,
        ];
        $next = $this->nextActions->resolve($order, $facts);
        [$blockerCode, $blockerMessage] = $this->blocker($order, $facts);
        $milestone = $this->milestone($order, $facts);
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
            invoiceTotal: $financial->issuedInvoiceTotal,
            paidTotal: $financial->invoicePaidAmount,
            creditedTotal: $financial->invoiceCreditedAmount,
            outstandingReceivable: $financial->invoiceOutstandingAmount,
            blockerCode: $blockerCode,
            blockerMessage: $blockerMessage,
            nextActionOwner: $next['owner'],
            nextActionLabel: $next['label'],
            nextActionRoute: $next['route'],
            financiallySettled: $financial->financiallySettled,
            completionWindowStartedAt: $this->toImmutable($order->completion_window_started_at),
            autoCloseDueAt: $this->toImmutable($order->auto_close_due_at),
            closeSource: $order->closed_by_source,
            daysUntilAutoClose: $order->auto_close_due_at !== null
                ? max(0, (int) now()->diffInDays($order->auto_close_due_at, false))
                : null,
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
        if (($facts['fully_invoiced'] ?? 0.0) < 0.5) {
            return ($facts['draft_invoice_count'] ?? 0.0) > 0.0 ? 'Invoice Draft' : 'Invoice Pending';
        }
        if (($facts['financially_settled'] ?? 0.0) < 0.5) {
            return 'Payment Pending';
        }
        if (($facts['auto_close_due'] ?? 0.0) > 0.5) {
            return 'Auto Close Pending';
        }

        return 'Awaiting Customer Confirmation';
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
        if (($facts['fully_invoiced'] ?? 0.0) < 0.5) {
            return ($facts['draft_invoice_count'] ?? 0.0) > 0.0
                ? ['invoice_draft', 'A draft invoice exists but has not been issued.']
                : ['invoice_pending', 'Delivered quantity is waiting for invoice coverage.'];
        }
        if (($facts['financially_settled'] ?? 0.0) < 0.5) {
            return ['payment_pending', 'Issued invoice value remains unpaid.'];
        }

        return [null, null];
    }

    private function toImmutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        /** @var Carbon $value */
        return CarbonImmutable::instance($value);
    }
}
