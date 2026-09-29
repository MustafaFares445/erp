<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderCompletionEligibility;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The single canonical answer to "can this Order be finally closed" —
 * consumed by both the workflow projection and {@see OrderCompletionService}
 * so the UI and the domain mutation never disagree
 * (IERP_ORDER_CUSTOMER_CLOSE_PAYMENT_FLOW plan §5.4, GAP-02).
 */
final readonly class OrderCompletionEligibilityService
{
    private const float Epsilon = 0.000001;

    public function __construct(
        private OrderFulfillmentQuantityService $quantities,
        private OrderFinancialProjectionService $financials,
    ) {}

    public function evaluate(Order $order, bool $requireCustomerEvidence = false): OrderCompletionEligibility
    {
        $totals = $this->quantities->totals($order);
        $effectiveOrdered = max(0.0, $totals['ordered'] - $totals['short_closed']);

        $orderReleased = $order->status === OrderStatus::Released;
        $fulfillmentComplete = $totals['remaining'] <= self::Epsilon
            && $effectiveOrdered <= $totals['arrived'] + self::Epsilon;

        $shipmentStatuses = $order->shipments()
            ->where('status', '!=', ShipmentStatus::Cancelled->value)
            ->pluck('status');
        $allShipmentsArrived = $shipmentStatuses->every(
            static fn (mixed $status): bool => $status === ShipmentStatus::Arrived || $status === ShipmentStatus::Arrived->value,
        );

        $noOpenProcurement = ! $order->procurementRequirements()
            ->whereNotIn('status', ['fulfilled', 'cancelled', 'superseded'])
            ->exists();

        $fullyInvoiced = $effectiveOrdered <= $totals['invoiced'] + self::Epsilon;

        $financial = $this->financials->project($order);
        $hasCustomerEvidence = $order->completionConfirmation()->exists();

        $blockers = [];

        if (! $orderReleased) {
            $blockers[] = ['code' => 'order_not_released', 'message' => 'The order must be released before it can be completed.'];
        }
        if (! $fulfillmentComplete) {
            $blockers[] = ['code' => 'remaining_fulfillment', 'message' => 'Some ordered demand is not yet dispatched and arrived.'];
        }
        if (! $noOpenProcurement) {
            $blockers[] = ['code' => 'procurement_open', 'message' => 'An open procurement requirement is still blocking delivery.'];
        }
        if (! $allShipmentsArrived) {
            $blockers[] = ['code' => 'shipment_not_arrived', 'message' => 'At least one shipment has not been confirmed as arrived.'];
        }
        if (! $fullyInvoiced) {
            $blockers[] = $financial->draftInvoiceCount > 0
                ? ['code' => 'invoice_draft', 'message' => 'A draft invoice must be issued before the order can be completed.']
                : ['code' => 'invoice_missing', 'message' => 'Delivered goods have not been invoiced yet.'];
        } elseif (! $financial->financiallySettled) {
            $blockers[] = ['code' => 'invoice_outstanding', 'message' => 'An issued invoice still has an outstanding balance.'];
        }
        if ($requireCustomerEvidence && ! $hasCustomerEvidence) {
            $blockers[] = ['code' => 'customer_evidence_missing', 'message' => 'Completion evidence from the customer is required.'];
        }

        return new OrderCompletionEligibility(
            eligible: $blockers === [],
            orderReleased: $orderReleased,
            fulfillmentComplete: $fulfillmentComplete,
            allShipmentsArrived: $allShipmentsArrived,
            noOpenProcurement: $noOpenProcurement,
            fullyInvoiced: $fullyInvoiced,
            financiallySettled: $financial->financiallySettled,
            hasCustomerEvidence: $hasCustomerEvidence,
            completionWindowStartedAt: $this->toImmutable($order->completion_window_started_at),
            autoCloseDueAt: $this->toImmutable($order->auto_close_due_at),
            blockers: $blockers,
        );
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
