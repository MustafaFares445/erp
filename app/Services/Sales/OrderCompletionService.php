<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Data\Sales\OrderCompletionEligibility;
use App\Enums\OrderCloseSource;
use App\Enums\OrderStatus;
use App\Enums\ShipmentStatus;
use App\Events\OrderClosed;
use App\Events\OrderCompletionWindowStarted;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\OrderCompletionConfirmation;
use App\Models\SalesSetting;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns every transition into {@see OrderStatus::Closed} — the single domain
 * service the plan requires so Sales, the customer and the scheduler all
 * enforce the exact same completion rules (GAP-01/GAP-02).
 *
 * A Sales user has no path into this class: only the customer (via
 * {@see self::closeByCustomer()}) and the scheduler (via
 * {@see self::closeAutomatically()}) can ever set `Closed`.
 */
final readonly class OrderCompletionService
{
    private const int MaxEvidenceFileBytes = 15 * 1024 * 1024;

    /** @var list<string> */
    private const array AllowedEvidenceMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(
        private OrderCompletionEligibilityService $eligibility,
    ) {}

    /** @param list<UploadedFile> $evidenceFiles */
    public function closeByCustomer(CustomerProfile $customer, Order $order, array $evidenceFiles, ?string $note = null): Order
    {
        return DB::transaction(function () use ($customer, $order, $evidenceFiles, $note): Order {
            $locked = $this->lock($order);

            if ($locked->status === OrderStatus::Closed) {
                return $locked;
            }

            if ($locked->customer_id !== $customer->getKey()) {
                throw new DomainException('This order does not belong to the confirming customer.');
            }

            if ($locked->status !== OrderStatus::Released) {
                throw new DomainException('The order must be released and fully delivered before the customer can complete it.');
            }

            $result = $this->eligibility->evaluate($locked, requireCustomerEvidence: false);

            if (! $result->eligible) {
                throw new DomainException('The order is not yet eligible for completion: '.$this->blockerSummary($result));
            }

            if ($evidenceFiles === []) {
                throw new DomainException('Customer completion requires at least one evidence file.');
            }

            foreach ($evidenceFiles as $file) {
                $this->assertValidEvidence($file);
            }

            $confirmation = OrderCompletionConfirmation::query()->create([
                'order_id' => $locked->getKey(),
                'customer_id' => $customer->getKey(),
                'confirmed_at' => now(),
                'source_channel' => 'customer_app',
                'note' => $note,
            ]);

            foreach ($evidenceFiles as $file) {
                $confirmation->addMedia($file)->toMediaCollection('order-completion-evidence', 'local');
            }

            $locked->forceFill([
                'status' => OrderStatus::Closed,
                'closed_at' => now(),
                'closed_by_source' => OrderCloseSource::Customer,
                'pending_reason' => null,
            ])->save();

            activity()->performedOn($locked)->causedBy($customer)
                ->withProperties([
                    'source_channel' => 'customer_app',
                    'customer_id' => $customer->getKey(),
                    'evidence_count' => count($evidenceFiles),
                    'auto_close_due_at' => $locked->auto_close_due_at?->toIso8601String(),
                ])
                ->log('sales.order.closed_by_customer');

            $closed = $locked->refresh();

            OrderClosed::dispatch($closed, OrderCloseSource::Customer);

            return $closed;
        }, attempts: 5);
    }

    public function closeAutomatically(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = $this->lock($order);

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            if ($locked->status !== OrderStatus::Released) {
                return $locked;
            }

            if ($locked->auto_close_due_at === null || $locked->auto_close_due_at->isAfter(now())) {
                return $locked;
            }

            $result = $this->eligibility->evaluate($locked, requireCustomerEvidence: false);

            if (! $result->eligible) {
                return $locked;
            }

            $locked->forceFill([
                'status' => OrderStatus::Closed,
                'closed_at' => now(),
                'closed_by_source' => OrderCloseSource::System,
                'pending_reason' => null,
            ])->save();

            activity()->performedOn($locked)
                ->withProperties([
                    'source_channel' => 'system',
                    'auto_close_days_snapshot' => $locked->auto_close_days_snapshot,
                    'completion_window_started_at' => $locked->completion_window_started_at?->toIso8601String(),
                    'auto_close_due_at' => $locked->auto_close_due_at?->toIso8601String(),
                ])
                ->log('sales.order.auto_closed');

            $closed = $locked->refresh();

            OrderClosed::dispatch($closed, OrderCloseSource::System);

            return $closed;
        }, attempts: 5);
    }

    public function refreshCompletionWindow(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $locked = $this->lock($order);

            if ($locked->status !== OrderStatus::Released) {
                return $locked;
            }

            $result = $this->eligibility->evaluate($locked, requireCustomerEvidence: false);
            $physicallyComplete = $result->fulfillmentComplete
                && $result->allShipmentsArrived
                && $result->noOpenProcurement;

            if (! $physicallyComplete) {
                if ($locked->completion_window_started_at !== null) {
                    $locked->forceFill([
                        'completion_window_started_at' => null,
                        'auto_close_days_snapshot' => null,
                        'auto_close_due_at' => null,
                    ])->save();
                }

                return $locked->refresh();
            }

            $latestArrival = $locked->shipments()
                ->where('status', ShipmentStatus::Arrived->value)
                ->max('confirmed_at');
            $windowStart = is_string($latestArrival) ? Carbon::parse($latestArrival) : now();

            if ($locked->completion_window_started_at !== null
                && $locked->completion_window_started_at->equalTo($windowStart)) {
                return $locked;
            }

            $days = SalesSetting::current()->customer_order_auto_close_days;

            $locked->forceFill([
                'completion_window_started_at' => $windowStart,
                'auto_close_days_snapshot' => $days,
                'auto_close_due_at' => $windowStart->copy()->addDays($days),
            ])->save();

            activity()->performedOn($locked)
                ->withProperties([
                    'source_channel' => 'system',
                    'completion_window_started_at' => $windowStart->toIso8601String(),
                    'auto_close_days_snapshot' => $days,
                ])
                ->log('sales.order.completion_window_started');

            $refreshed = $locked->refresh();

            OrderCompletionWindowStarted::dispatch($refreshed);

            return $refreshed;
        }, attempts: 5);
    }

    private function lock(Order $order): Order
    {
        return Order::query()->whereKey($order->getKey())->lockForUpdate()->sole();
    }

    private function assertValidEvidence(UploadedFile $file): void
    {
        if (! in_array($file->getMimeType(), self::AllowedEvidenceMimeTypes, true)) {
            throw new DomainException('Completion evidence must be a JPEG, PNG, WEBP or PDF file.');
        }

        if ($file->getSize() === false || $file->getSize() > self::MaxEvidenceFileBytes) {
            throw new DomainException('A completion evidence file exceeds the maximum allowed size.');
        }
    }

    private function blockerSummary(OrderCompletionEligibility $result): string
    {
        return collect($result->blockers)->pluck('message')->implode(' ');
    }
}
