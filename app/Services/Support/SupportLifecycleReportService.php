<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\CalibrationResult;
use App\Enums\CommissioningStatus;
use App\Enums\EquipmentLoanStatus;
use App\Enums\ExternalRepairStatus;
use App\Enums\MaintenanceKind;
use App\Enums\SupportPermission;
use App\Enums\TicketType;
use App\Models\CustomerReturnRequest;
use App\Models\EquipmentCalibration;
use App\Models\EquipmentInstallation;
use App\Models\EquipmentLoan;
use App\Models\InventoryReturnLine;
use App\Models\MaintenanceExternalRepair;
use App\Models\MaintenanceSchedule;
use App\Models\TicketProductContext;
use App\Models\TicketQualityResolution;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Final after-sales lifecycle reports introduced by the dental-lab rollout.
 *
 * These reports are read-only. Support aggregates state owned by Support,
 * Inventory, Sales and Purchasing without changing custody or financial data.
 */
final readonly class SupportLifecycleReportService
{
    public function canView(User $actor): bool
    {
        return $actor->can(SupportPermission::ReportView->value);
    }

    /** @throws DomainException */
    public function authorizeView(User $actor): void
    {
        if (! $this->canView($actor)) {
            throw new DomainException('You are not authorized to view Support reports.');
        }
    }

    /**
     * @return array{
     *     completed:int,
     *     pending_commissioning:int,
     *     commissioned:int,
     *     commissioning_failed:int,
     *     commissioning_failure_rate_percent:float|null,
     *     average_delivery_to_installation_hours:float|null
     * }
     */
    public function installations(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $installed = EquipmentInstallation::query()
            ->whereNotNull('installed_at')
            ->with('shipment:id,confirmed_at');
        $this->applyPeriod($installed, 'installed_at', $from, $until);

        $completedRows = $installed->get(['id', 'shipment_id', 'installed_at']);
        $deliveryToInstallationHours = [];

        foreach ($completedRows as $installation) {
            $deliveredAt = $installation->shipment?->confirmed_at;

            if ($deliveredAt !== null && $installation->installed_at !== null && ! $installation->installed_at->lt($deliveredAt)) {
                $deliveryToInstallationHours[] = (float) $deliveredAt->diffInMinutes($installation->installed_at) / 60;
            }
        }

        $commissioned = EquipmentInstallation::query()->whereNotNull('commissioned_at');
        $this->applyPeriod($commissioned, 'commissioned_at', $from, $until);
        $commissionedCount = (clone $commissioned)->count();
        $failed = (clone $commissioned)->where('commissioning_status', CommissioningStatus::Failed->value)->count();

        $pending = EquipmentInstallation::query()
            ->whereNotNull('installed_at')
            ->where('commissioning_status', CommissioningStatus::Pending->value)
            ->count();

        return [
            'completed' => $completedRows->count(),
            'pending_commissioning' => $pending,
            'commissioned' => $commissionedCount,
            'commissioning_failed' => $failed,
            'commissioning_failure_rate_percent' => $commissionedCount > 0
                ? round(($failed / $commissionedCount) * 100, 1)
                : null,
            'average_delivery_to_installation_hours' => $deliveryToInstallationHours === []
                ? null
                : round(array_sum($deliveryToInstallationHours) / count($deliveryToInstallationHours), 1),
        ];
    }

    /**
     * @return array{
     *     due_soon:int,
     *     overdue:int,
     *     completed:int,
     *     passed:int,
     *     failed:int,
     *     pass_rate_percent:float|null,
     *     failure_rate_percent:float|null
     * }
     */
    public function calibrations(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $today = today();
        $dueSoon = MaintenanceSchedule::query()
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->where('is_active', true)
            ->whereDate('next_due_on', '>=', $today->toDateString())
            ->whereDate('next_due_on', '<=', $today->copy()->addDays(30)->toDateString())
            ->count();
        $overdue = MaintenanceSchedule::query()
            ->where('maintenance_kind', MaintenanceKind::Calibration->value)
            ->where('is_active', true)
            ->whereDate('next_due_on', '<', $today->toDateString())
            ->count();

        $completed = EquipmentCalibration::query()->whereNotNull('result')->whereNotNull('calibrated_at');
        $this->applyPeriod($completed, 'calibrated_at', $from, $until);
        $completedCount = (clone $completed)->count();
        $failed = (clone $completed)->where('result', CalibrationResult::Failed->value)->count();
        $passed = $completedCount - $failed;

        return [
            'due_soon' => $dueSoon,
            'overdue' => $overdue,
            'completed' => $completedCount,
            'passed' => $passed,
            'failed' => $failed,
            'pass_rate_percent' => $completedCount > 0 ? round(($passed / $completedCount) * 100, 1) : null,
            'failure_rate_percent' => $completedCount > 0 ? round(($failed / $completedCount) * 100, 1) : null,
        ];
    }

    /**
     * @return array{
     *     active:int,
     *     overdue:int,
     *     utilization_count:int,
     *     returned:int,
     *     average_loan_duration_days:float|null
     * }
     */
    public function loaners(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $active = EquipmentLoan::query()->whereIn('status', EquipmentLoanStatus::activeValues())->count();
        $overdue = EquipmentLoan::query()
            ->where('status', EquipmentLoanStatus::Issued->value)
            ->whereNotNull('expected_return_at')
            ->where('expected_return_at', '<', now())
            ->count();

        $issued = EquipmentLoan::query()->whereNotNull('issued_at');
        $this->applyPeriod($issued, 'issued_at', $from, $until);
        $utilizationCount = (clone $issued)->count();

        $returned = EquipmentLoan::query()
            ->where('status', EquipmentLoanStatus::Returned->value)
            ->whereNotNull('issued_at')
            ->whereNotNull('returned_at');
        $this->applyPeriod($returned, 'returned_at', $from, $until);
        $returnedRows = $returned->get(['issued_at', 'returned_at']);

        $durations = $returnedRows
            ->map(static fn (EquipmentLoan $loan): float => $loan->issued_at !== null && $loan->returned_at !== null
                ? (float) $loan->issued_at->diffInMinutes($loan->returned_at) / 1440
                : 0.0)
            ->filter(static fn (float $days): bool => $days >= 0)
            ->values();

        return [
            'active' => $active,
            'overdue' => $overdue,
            'utilization_count' => $utilizationCount,
            'returned' => $returnedRows->count(),
            'average_loan_duration_days' => $durations->isEmpty() ? null : round((float) $durations->avg(), 1),
        ];
    }

    /**
     * @return array{
     *     open:int,
     *     awaiting_supplier:int,
     *     completed:int,
     *     average_supplier_turnaround_days:float|null,
     *     warranty_recovery_outstanding_minor:int
     * }
     */
    public function rma(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $open = MaintenanceExternalRepair::query()->whereIn('status', ExternalRepairStatus::openValues())->count();
        $awaitingSupplier = MaintenanceExternalRepair::query()
            ->whereIn('status', [
                ExternalRepairStatus::ShippedToSupplier->value,
                ExternalRepairStatus::ReceivedBySupplier->value,
                ExternalRepairStatus::Repairing->value,
                ExternalRepairStatus::Repaired->value,
                ExternalRepairStatus::ReplacementApproved->value,
            ])
            ->count();

        $completed = MaintenanceExternalRepair::query()
            ->whereNotNull('returned_at')
            ->whereNotNull('requested_at');
        $this->applyPeriod($completed, 'returned_at', $from, $until);
        $completedRows = $completed->get(['requested_at', 'returned_at']);

        $turnaround = $completedRows
            ->map(static fn (MaintenanceExternalRepair $repair): float => $repair->returned_at !== null
                ? (float) $repair->requested_at->diffInMinutes($repair->returned_at) / 1440
                : 0.0)
            ->filter(static fn (float $days): bool => $days >= 0)
            ->values();

        $recoveryOutstanding = WarrantyRecoveryClaim::query()
            ->get(['claimed_amount_minor', 'approved_amount_minor', 'received_amount_minor'])
            ->sum(static fn (WarrantyRecoveryClaim $claim): int => $claim->outstandingMinor());

        return [
            'open' => $open,
            'awaiting_supplier' => $awaitingSupplier,
            'completed' => $completedRows->count(),
            'average_supplier_turnaround_days' => $turnaround->isEmpty() ? null : round((float) $turnaround->avg(), 1),
            'warranty_recovery_outstanding_minor' => (int) $recoveryOutstanding,
        ];
    }

    /**
     * @return array{
     *     complaints:int,
     *     affected_quantity:float,
     *     returned_quantity:float,
     *     by_product:list<array{product_variant_id:int,product:string,complaints:int,affected_quantity:float}>,
     *     by_lot:list<array{inventory_lot_id:int,lot_number:string,complaints:int,affected_customers:int,affected_quantity:float}>,
     *     top_problematic_lots:list<array{inventory_lot_id:int,lot_number:string,complaints:int,affected_customers:int,affected_quantity:float}>
     * }
     */
    public function quality(User $actor, ?Carbon $from, ?Carbon $until): array
    {
        $this->authorizeView($actor);

        $contexts = TicketProductContext::query()
            ->whereHas('ticket', function (Builder $tickets) use ($from, $until): void {
                $tickets->where('type', TicketType::ProductQualityIssue->value);
                $this->applyPeriod($tickets, 'created_at', $from, $until);
            })
            ->with([
                'ticket:id,customer_id,type,created_at',
                'productVariant:id,product_id,name',
                'productVariant.product:id,name',
                'inventoryLot:id,canonical_inventory_lot_id,lot_number',
            ])
            ->get();

        $ticketIds = $contexts->pluck('ticket_id')->unique()->values();
        $returnedQuantity = 0.0;

        if ($ticketIds->isNotEmpty()) {
            $returnRequestIds = TicketQualityResolution::query()
                ->whereIn('ticket_id', $ticketIds)
                ->whereNotNull('customer_return_request_id')
                ->pluck('customer_return_request_id')
                ->unique()
                ->values();

            if ($returnRequestIds->isNotEmpty()) {
                $inventoryReturnIds = CustomerReturnRequest::query()
                    ->whereIn('id', $returnRequestIds)
                    ->whereNotNull('resulting_inventory_return_id')
                    ->pluck('resulting_inventory_return_id')
                    ->unique()
                    ->values();

                if ($inventoryReturnIds->isNotEmpty()) {
                    $returnedQuantity = (float) InventoryReturnLine::query()
                        ->whereIn('inventory_return_id', $inventoryReturnIds)
                        ->sum('transaction_quantity');
                }
            }
        }

        $byProduct = array_values($contexts
            ->groupBy('product_variant_id')
            ->map(static function (Collection $rows, int|string $variantId): array {
                /** @var TicketProductContext|null $first */
                $first = $rows->first();
                $variant = $first instanceof TicketProductContext ? $first->productVariant : null;
                $product = $variant?->product;
                $quantity = $rows->sum('quantity');

                return [
                    'product_variant_id' => (int) $variantId,
                    'product' => $product !== null
                        ? $product->name
                        : ($variant !== null ? $variant->name : '—'),
                    'complaints' => $rows->pluck('ticket_id')->unique()->count(),
                    'affected_quantity' => round(is_numeric($quantity) ? (float) $quantity : 0.0, 6),
                ];
            })
            ->sortByDesc('complaints')
            ->values()
            ->all());

        $lotRows = $contexts
            ->filter(static fn (TicketProductContext $context): bool => $context->inventory_lot_id !== null)
            ->groupBy(static fn (TicketProductContext $context): int => $context->inventoryLot->canonical_inventory_lot_id ?? (int) $context->inventory_lot_id)
            ->map(static function (Collection $rows, int|string $lotId): array {
                /** @var TicketProductContext|null $first */
                $first = $rows->first();
                $lot = $first instanceof TicketProductContext ? $first->inventoryLot : null;
                $quantity = $rows->sum('quantity');

                return [
                    'inventory_lot_id' => (int) $lotId,
                    'lot_number' => $lot !== null && is_string($lot->lot_number) && $lot->lot_number !== ''
                        ? $lot->lot_number
                        : '—',
                    'complaints' => $rows->pluck('ticket_id')->unique()->count(),
                    'affected_customers' => $rows->pluck('ticket.customer_id')->filter()->unique()->count(),
                    'affected_quantity' => round(is_numeric($quantity) ? (float) $quantity : 0.0, 6),
                ];
            })
            ->sortByDesc('complaints')
            ->values();

        $affectedQuantity = $contexts->sum('quantity');

        return [
            'complaints' => $ticketIds->count(),
            'affected_quantity' => round(is_numeric($affectedQuantity) ? (float) $affectedQuantity : 0.0, 6),
            'returned_quantity' => round($returnedQuantity, 6),
            'by_product' => $byProduct,
            'by_lot' => array_values($lotRows->all()),
            'top_problematic_lots' => array_values($lotRows->take(10)->values()->all()),
        ];
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private function applyPeriod(Builder $query, string $column, ?Carbon $from, ?Carbon $until): void
    {
        if ($from instanceof Carbon) {
            $query->where($column, '>=', $from->copy()->startOfDay());
        }

        if ($until instanceof Carbon) {
            $query->where($column, '<=', $until->copy()->endOfDay());
        }
    }
}
