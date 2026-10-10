<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\OperationStage;
use App\Enums\ReservationStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReservation;
use App\Models\InventoryReservationAllocation;
use App\Models\User;
use App\Services\Inventory\InventoryReservationService;
use Illuminate\Database\Eloquent\Model;

/**
 * Checks that an active reservation still belongs to a live operation line: an operation
 * reserves at Mark Ready and its reservations are consumed at dispatch/completion or released at
 * cancellation ({@see InventoryReservationService}), so an active reservation on any other stage,
 * or one that disagrees with its line, holds stock that nothing will ever use.
 */
final readonly class InventoryReservationDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const float Epsilon = 0.000001;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryReservation;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryReservation);

        $record->loadMissing(['allocations.lot', 'allocations.serializedUnit', 'releasedBy', 'productVariant']);

        $findings = match ($record->status) {
            ReservationStatus::Active => $this->activeFindings($record),
            ReservationStatus::Consumed => [$this->finding('reservation.consumed', Category::Reservation, Severity::Notice,
                ['date' => $record->consumed_at?->toDateTimeString() ?? '—'])],
            ReservationStatus::Released => [$this->finding('reservation.released', Category::Reservation, Severity::Notice,
                ['date' => $record->released_at?->toDateTimeString() ?? '—'],
                facts: [$this->fact('reservation_released_by', [
                    'name' => $record->releasedBy instanceof User ? $record->releasedBy->name : __('admin.inventory.reservation.system'),
                    'reason' => $record->release_reason ?? '—',
                ])])],
            ReservationStatus::Expired => [$this->finding('reservation.expired', Category::Reservation, Severity::Notice,
                ['date' => $record->released_at?->toDateTimeString() ?? $record->expires_at?->toDateTimeString() ?? '—'])],
        };

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('reservation_expiry'),
                $this->check('reservation_source'),
                $this->check('reservation_line'),
                $this->check('reservation_allocations'),
            ],
            limits: [
                $this->limit('recorded_balances'),
                $this->limit('reservation_other_sources'),
            ],
        );
    }

    /**
     * @return list<array{label: string, value: string, helper?: string|null, tone?: string|null}>
     */
    public function keyFigures(InventoryReservation $reservation): array
    {
        $reservation->loadMissing(['allocations', 'productVariant.unit']);
        $unit = $reservation->productVariant?->unit;
        $expiresAt = $reservation->expires_at;
        $overdue = $reservation->isActive() && $expiresAt !== null && $expiresAt->lessThanOrEqualTo(now());

        return [
            [
                'label' => __('inventory_view_ux.ui.metric_quantity'),
                'value' => $this->quantity($reservation->base_quantity, $unit !== null ? ($unit->symbol ?: $unit->code) : null),
            ],
            ['label' => __('inventory_view_ux.ui.metric_status'), 'value' => $reservation->status->getLabel()],
            ['label' => __('inventory_view_ux.ui.metric_allocations'), 'value' => (string) $reservation->allocations->count()],
            [
                'label' => __('inventory_view_ux.ui.metric_expires'),
                'value' => match (true) {
                    $expiresAt === null => __('inventory_view_ux.ui.metric_no_expiry'),
                    ! $reservation->isActive() => $expiresAt->toDateString(),
                    $overdue => __('inventory_view_ux.ui.metric_overdue'),
                    default => $expiresAt->diffForHumans(),
                },
                'helper' => $expiresAt?->toDateTimeString(),
                'tone' => $overdue ? 'danger' : null,
            ],
        ];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function activeFindings(InventoryReservation $reservation): array
    {
        $findings = [];

        if ($reservation->expires_at !== null && $reservation->expires_at->lessThanOrEqualTo(now())) {
            $findings[] = $this->finding('reservation.overdue', Category::Reservation, Severity::Warning,
                ['date' => $reservation->expires_at->toDateTimeString()],
                action: $this->releaseStep($reservation),
            );
        }

        if ($reservation->source_type !== 'inventory_operation') {
            $findings[] = $this->finding('reservation.other_source', Category::Reservation, Severity::Notice,
                ['source' => $reservation->source_type.' #'.$reservation->source_id],
            );

            return [...$findings, ...$this->allocationFindings($reservation, null)];
        }

        $operation = InventoryOperation::withTrashed()
            ->with(['sourceWarehouse', 'lines'])
            ->find($reservation->source_id);

        if (! $operation instanceof InventoryOperation) {
            $findings[] = $this->finding('reservation.source_missing', Category::DataQuality, Severity::Blocker,
                ['source' => '#'.$reservation->source_id],
                action: $this->releaseStep($reservation),
            );

            return $findings;
        }

        $number = (string) ($operation->operation_number ?? '#'.$operation->id);

        if ($operation->trashed()) {
            $findings[] = $this->finding('reservation.source_deleted', Category::DataQuality, Severity::Blocker,
                ['number' => $number],
                action: $this->releaseStep($reservation),
            );

            return $findings;
        }

        $openLink = $this->viewLink(InventoryOperationResource::class, $operation, 'reservation_open_operation', ['number' => $number]);

        $findings[] = match ($operation->stage) {
            OperationStage::Ready => $this->finding('reservation.held_for_operation', Category::Reservation, Severity::Notice,
                ['number' => $number], action: $openLink),
            OperationStage::Draft, OperationStage::Waiting => $this->finding('reservation.source_not_ready', Category::Reservation, Severity::Warning,
                ['number' => $number, 'stage' => $operation->stageLabel()], action: $this->releaseStep($reservation)),
            default => $this->finding('reservation.source_closed', Category::Reservation, Severity::Blocker,
                ['number' => $number, 'stage' => $operation->stageLabel()], action: $this->releaseStep($reservation)),
        };

        $line = $operation->lines->firstWhere('id', $reservation->source_line_id);

        if (! $line instanceof InventoryOperationLine) {
            $findings[] = $this->finding('reservation.line_missing', Category::DataQuality, Severity::Blocker,
                ['number' => $number, 'line' => '#'.($reservation->source_line_id ?? '—')],
                action: $openLink,
            );

            return [...$findings, ...$this->allocationFindings($reservation, null)];
        }

        $mismatches = [];

        if ($line->product_variant_id !== $reservation->product_variant_id) {
            $mismatches[] = $this->fact('reservation_variant_mismatch', [
                'reserved' => '#'.$reservation->product_variant_id,
                'line' => '#'.$line->product_variant_id,
            ]);
        }

        if ($operation->source_warehouse_id !== $reservation->warehouse_id) {
            $mismatches[] = $this->fact('reservation_warehouse_mismatch', [
                'reserved' => '#'.$reservation->warehouse_id,
                'source' => $operation->source_warehouse_id !== null ? '#'.$operation->source_warehouse_id : '—',
            ]);
        }

        if ($line->base_quantity !== null && abs((float) $line->base_quantity - (float) $reservation->base_quantity) > self::Epsilon) {
            $mismatches[] = $this->fact('reservation_quantity_mismatch', [
                'reserved' => $this->quantity($reservation->base_quantity),
                'line' => $this->quantity($line->base_quantity),
            ]);
        }

        if ($mismatches !== []) {
            $findings[] = $this->finding('reservation.line_mismatch', Category::DataQuality, Severity::Blocker,
                ['number' => $number],
                facts: $mismatches,
                action: $this->guidance('escalate_inconsistency'),
            );
        }

        return [...$findings, ...$this->allocationFindings($reservation, $line)];
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function allocationFindings(InventoryReservation $reservation, ?InventoryOperationLine $line): array
    {
        $findings = [];
        $allocations = $reservation->allocations;
        $allocated = (float) $allocations->sum(static fn (InventoryReservationAllocation $allocation): float => (float) $allocation->base_quantity);

        if (abs($allocated - (float) $reservation->base_quantity) > self::Epsilon) {
            $findings[] = $this->finding('reservation.allocation_quantity', Category::DataQuality, Severity::Warning,
                facts: [$this->fact('reservation_allocated', [
                    'allocated' => $this->quantity($allocated),
                    'reserved' => $this->quantity($reservation->base_quantity),
                    'count' => $allocations->count(),
                ])],
            );
        }

        if (! $line instanceof InventoryOperationLine) {
            return $findings;
        }

        $mismatched = $allocations->filter(static fn (InventoryReservationAllocation $allocation): bool => $allocation->inventory_lot_id !== $line->inventory_lot_id
            || $allocation->serialized_inventory_unit_id !== $line->serialized_inventory_unit_id);

        if ($mismatched->isNotEmpty()) {
            $findings[] = $this->finding('reservation.allocation_mismatch', Category::Tracking, Severity::Blocker,
                facts: array_values($mismatched->map(fn (InventoryReservationAllocation $allocation): string => $this->fact('reservation_allocation_tracking', [
                    'lot' => $allocation->lot !== null ? (string) $allocation->lot->lot_number : '—',
                    'serial' => $allocation->serializedUnit !== null ? $allocation->serializedUnit->serial_number : '—',
                    'line_lot' => $line->inventory_lot_id !== null ? '#'.$line->inventory_lot_id : '—',
                    'line_serial' => $line->serialized_inventory_unit_id !== null ? '#'.$line->serialized_inventory_unit_id : '—',
                ]))->all()),
                action: $this->guidance('escalate_inconsistency'),
            );
        }

        return $findings;
    }

    private function releaseStep(InventoryReservation $reservation): InventoryDiagnosticAction
    {
        $label = $this->actionLabel('reservation_use_button', ['button' => __('admin.inventory.reservation.actions.release')]);

        return auth()->user()?->can('release', $reservation) ?? false
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }
}
