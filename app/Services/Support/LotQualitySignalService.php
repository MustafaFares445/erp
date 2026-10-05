<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\NotificationEventKey;
use App\Enums\TicketStatus;
use App\Events\SupportQualityMilestone;
use App\Models\InventoryLot;
use App\Models\LotQualityAlert;
use App\Models\Ticket;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only quality signals for an Inventory lot, plus the "potential lot
 * quality issue" alert raised when open complaints reach the threshold. The
 * alert is information only: Inventory alone decides quarantine or
 * disposition, and nothing here changes stock.
 */
final readonly class LotQualitySignalService
{
    /** Ticket statuses that no longer count as an open complaint. */
    private const array ClosedStatuses = [TicketStatus::Resolved, TicketStatus::Closed, TicketStatus::Cancelled];

    /**
     * @return array{open_complaints:int, total_complaints:int, affected_customers:int, affected_quantity:string, returns:int, alert:?LotQualityAlert}
     */
    public function summary(InventoryLot $lot): array
    {
        $contexts = $this->contexts($lot);

        $ticketIds = (clone $contexts)->pluck('ticket_id')->unique();
        $tickets = Ticket::query()->whereIn('id', $ticketIds);

        return [
            'open_complaints' => $this->openComplaints($lot),
            'total_complaints' => $ticketIds->count(),
            'affected_customers' => (clone $tickets)->distinct()->count('customer_id'),
            'affected_quantity' => number_format((float) (clone $contexts)->sum('quantity'), 6, '.', ''),
            'returns' => TicketQualityResolution::query()->whereIn('ticket_id', $ticketIds)->whereNotNull('customer_return_request_id')->distinct()->count('customer_return_request_id'),
            'alert' => LotQualityAlert::query()->where('inventory_lot_id', $this->canonicalId($lot))->first(),
        ];
    }

    public function openComplaints(InventoryLot $lot): int
    {
        return Ticket::query()
            ->whereNotIn('status', array_map(static fn (TicketStatus $status): string => $status->value, self::ClosedStatuses))
            ->whereIn('id', $this->contexts($lot)->select('ticket_id'))
            ->count();
    }

    /** Raises (once per lot) or refreshes the alert when open complaints reach the threshold. */
    public function evaluate(InventoryLot $lot): ?LotQualityAlert
    {
        if (! (bool) config('support.product_quality_enabled', false)) {
            return null;
        }

        $configuredThreshold = config('support.lot_complaint_threshold', 3);
        $threshold = max(1, is_numeric($configuredThreshold) ? (int) $configuredThreshold : 3);
        $open = $this->openComplaints($lot);

        if ($open < $threshold) {
            return null;
        }

        $canonical = InventoryLot::query()->findOrFail($this->canonicalId($lot));

        return DB::transaction(function () use ($canonical, $open, $threshold): LotQualityAlert {
            $alert = LotQualityAlert::query()->where('inventory_lot_id', $canonical->id)->lockForUpdate()->first();

            if ($alert instanceof LotQualityAlert) {
                $alert->update(['open_complaints' => $open]);

                return $alert;
            }

            $alert = LotQualityAlert::query()->create([
                'inventory_lot_id' => $canonical->id,
                'open_complaints' => $open,
                'threshold' => $threshold,
                'raised_at' => now(),
            ]);

            activity()
                ->performedOn($canonical)
                ->withProperties(['open_complaints' => $open, 'threshold' => $threshold])
                ->log('support.quality.lot_threshold_reached');

            DB::afterCommit(static fn () => SupportQualityMilestone::dispatch(NotificationEventKey::LotComplaintThresholdReached, $canonical));

            return $alert;
        });
    }

    public function acknowledge(LotQualityAlert $alert, User $actor): LotQualityAlert
    {
        Gate::forUser($actor)->authorize('create', TicketQualityResolution::class);

        if ($alert->acknowledged_at === null) {
            $alert->update(['acknowledged_at' => now(), 'acknowledged_by' => $actor->getKey()]);

            activity()
                ->performedOn($alert)
                ->causedBy($actor)
                ->withProperties(['source_channel' => 'dashboard'])
                ->log('support.quality.lot_alert_acknowledged');
        }

        return $alert;
    }

    /** @return Builder<TicketProductContext> contexts on the lot or on any lot merged into it */
    private function contexts(InventoryLot $lot): Builder
    {
        $canonicalId = $this->canonicalId($lot);

        return TicketProductContext::query()->whereIn('inventory_lot_id', InventoryLot::query()
            ->where(static fn (Builder $query): Builder => $query->whereKey($canonicalId)->orWhere('canonical_inventory_lot_id', $canonicalId))
            ->select('id'));
    }

    private function canonicalId(InventoryLot $lot): int
    {
        return $lot->canonical_inventory_lot_id ?? $lot->id;
    }
}
