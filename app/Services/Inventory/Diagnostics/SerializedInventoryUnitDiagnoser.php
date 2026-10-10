<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\MaintenanceStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Enums\SupportPermission;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryLots\InventoryLotResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceSchedules\MaintenanceScheduleResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Warehouse;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyPolicy;
use App\Services\Inventory\InventoryLotReconciliationService;
use App\Services\Inventory\InventoryPostingService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Explains where one serialized unit is, whether its stored custody contradicts itself, and
 * (for users who may see Support data) its warranty and maintenance situation.
 *
 * Custody contradictions are the ones {@see InventoryLotReconciliationService} reports;
 * {@see InventoryPostingService} writes status and custody independently per document, so no
 * other status/custody pairing is treated as wrong. Warranty findings never state coverage:
 * coverage is decided in the warranty assessment of a maintenance request.
 */
final readonly class SerializedInventoryUnitDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    public const int WarrantyExpiringDays = 30;

    public function supports(Model $record): bool
    {
        return $record instanceof SerializedInventoryUnit;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof SerializedInventoryUnit);

        $record->loadMissing([
            'warehouse:id,code,name',
            'lot:id,lot_number,product_variant_id,canonical_inventory_lot_id',
            'productVariant:id,sku,warranty_enabled',
        ]);

        $findings = [
            ...$this->custodyFindings($record),
            ...$this->lotFindings($record),
            ...$this->conditionFindings($record),
        ];
        $checks = [
            $this->check('serial_custody'),
            $this->check('serial_status'),
            $this->check('serial_lot'),
            $this->check('serial_condition'),
        ];
        $limits = [$this->limit('serial_physical')];

        if (self::canViewWarranty()) {
            $findings = [...$findings, ...$this->warrantyFindings($record)];
            $checks[] = $this->check('serial_warranty', ['days' => self::WarrantyExpiringDays]);
            $limits[] = $this->limit('serial_warranty_coverage');
        } else {
            $limits[] = $this->limit('serial_warranty_hidden');
        }

        if (self::canViewMaintenanceSchedules()) {
            $findings = [...$findings, ...$this->scheduleFindings($record)];
            $checks[] = $this->check('serial_schedules');
        }

        if (self::canViewMaintenanceRecords()) {
            $findings = [...$findings, ...$this->maintenanceRecordFindings($record)];
            $checks[] = $this->check('serial_maintenance_requests');
        }

        if (! self::canViewMaintenanceSchedules() || ! self::canViewMaintenanceRecords()) {
            $limits[] = $this->limit('serial_maintenance_hidden');
        }

        return new InventoryDiagnosis($findings, $checks, $limits);
    }

    /**
     * Warranty entitlements have no policy of their own; they are shown to users who may view
     * warranty policies or the Equipment 360 page (both of which show entitlements).
     */
    public static function canViewWarranty(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->can('viewAny', WarrantyPolicy::class) || $user->can(SupportPermission::Equipment360View->value));
    }

    public static function canViewMaintenanceRecords(): bool
    {
        return auth()->user()?->can('viewAny', MaintenanceRecord::class) ?? false;
    }

    public static function canViewMaintenanceSchedules(): bool
    {
        return auth()->user()?->can('viewAny', MaintenanceSchedule::class) ?? false;
    }

    public static function canViewMaintenanceCost(): bool
    {
        return auth()->user()?->can('viewCost', MaintenanceRecord::class) ?? false;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function custodyFindings(SerializedInventoryUnit $unit): array
    {
        $findings = [];
        $custody = $unit->custody_type;
        $status = $unit->status;
        $contradiction = $this->custodyContradiction($unit);

        if ($contradiction !== null) {
            $findings[] = $this->finding('serial.custody_contradiction', Category::Custody, Severity::Blocker,
                facts: [
                    $contradiction,
                    ...$this->stateFacts($unit),
                ],
                action: $this->guidance('escalate_inconsistency'),
            );
        }

        if ($status === SerializedInventoryUnitStatus::Pending) {
            $findings[] = $this->pendingReceiptFinding($unit);
        } elseif ($status === SerializedInventoryUnitStatus::AdjustedOut) {
            $findings[] = $this->adjustedOutFinding($unit);
        } elseif ($status === SerializedInventoryUnitStatus::Unknown || $custody === SerializedCustodyType::Unknown) {
            $findings[] = $this->finding('serial.custody_unknown', Category::DataQuality, Severity::Warning,
                facts: [...$this->stateFacts($unit), ...$this->referenceFacts($unit)],
                action: $this->movementsLink($unit),
            );
        }

        if ($custody === SerializedCustodyType::InTransit || $status === SerializedInventoryUnitStatus::InTransit) {
            $findings[] = $this->inTransitFinding($unit);
        }

        return $findings;
    }

    /**
     * The contradictions {@see InventoryLotReconciliationService::checkSerialReconciliation()} reports.
     */
    private function custodyContradiction(SerializedInventoryUnit $unit): ?string
    {
        if ($unit->custody_type !== SerializedCustodyType::Warehouse) {
            return $unit->warehouse_id !== null
                ? $this->fact('serial_custody_retains_warehouse', [
                    'custody' => $unit->custody_type->getLabel(),
                    'warehouse' => $this->warehouseLabel($unit),
                ])
                : null;
        }

        if ($unit->warehouse_id === null) {
            return $this->fact('serial_custody_without_warehouse');
        }

        if (! $unit->stock_condition->isMaterialized()) {
            return $this->fact('serial_custody_unmaterialized_condition', ['condition' => $unit->stock_condition->getLabel()]);
        }

        return null;
    }

    private function pendingReceiptFinding(SerializedInventoryUnit $unit): InventoryDiagnosticFinding
    {
        $line = InventoryOperationLine::query()
            ->where('serialized_inventory_unit_id', $unit->getKey())
            ->with('operation:id,operation_number,operation_type,stage')
            ->latest('id')
            ->first();
        $operation = $line instanceof InventoryOperationLine ? $line->operation : null;

        if (! $operation instanceof InventoryOperation) {
            return $this->finding('serial.pending_receipt', Category::Receipt, Severity::Notice,
                facts: [$this->fact('serial_no_receipt_line')],
            );
        }

        return $this->finding('serial.pending_receipt', Category::Receipt, Severity::Notice,
            facts: [$this->fact('serial_document', ['document' => $this->operationLabel($operation)])],
            action: $this->viewLink(InventoryOperationResource::class, $operation, 'serial_open_receipt'),
        );
    }

    private function adjustedOutFinding(SerializedInventoryUnit $unit): InventoryDiagnosticFinding
    {
        $adjustment = $unit->custody_reference_type === 'adjustment' && $unit->custody_reference_id !== null
            ? InventoryAdjustment::query()->find($unit->custody_reference_id)
            : null;

        if (! $adjustment instanceof InventoryAdjustment) {
            return $this->finding('serial.adjusted_out', Category::Custody, Severity::Notice,
                facts: $this->referenceFacts($unit),
                action: $this->movementsLink($unit),
            );
        }

        $number = $adjustment->adjustment_number;

        return $this->finding('serial.adjusted_out', Category::Custody, Severity::Notice,
            facts: [$this->fact('serial_document', [
                'document' => is_string($number) && $number !== '' ? $number : $this->keyLabel($adjustment),
            ])],
            action: $this->viewLink(AdjustmentResource::class, $adjustment, 'serial_open_adjustment'),
        );
    }

    private function inTransitFinding(SerializedInventoryUnit $unit): InventoryDiagnosticFinding
    {
        $operation = $unit->custody_reference_type === 'inventory_operation' && $unit->custody_reference_id !== null
            ? InventoryOperation::query()
                ->with(['sourceWarehouse:id,code,name', 'destinationWarehouse:id,code,name'])
                ->find($unit->custody_reference_id)
            : null;

        if (! $operation instanceof InventoryOperation) {
            return $this->finding('serial.in_transit', Category::Transfer, Severity::Notice,
                facts: [$this->fact('serial_no_transfer_reference')],
                action: $this->movementsLink($unit),
            );
        }

        return $this->finding('serial.in_transit', Category::Transfer, Severity::Notice,
            facts: [$this->fact('serial_transfer', [
                'document' => $this->operationLabel($operation),
                'from' => $this->optionalWarehouseLabel($operation->sourceWarehouse),
                'to' => $this->optionalWarehouseLabel($operation->destinationWarehouse),
            ])],
            action: $this->viewLink(InventoryOperationResource::class, $operation, 'open_transfer'),
        );
    }

    /**
     * The lot identity checks {@see InventoryLotReconciliationService} applies to serialized units.
     *
     * @return list<InventoryDiagnosticFinding>
     */
    private function lotFindings(SerializedInventoryUnit $unit): array
    {
        if ($unit->inventory_lot_id === null) {
            return [];
        }

        $lot = $unit->lot;

        if (! $lot instanceof InventoryLot) {
            return [$this->finding('serial.lot_mismatch', Category::DataQuality, Severity::Blocker,
                facts: [$this->fact('serial_lot_missing', ['lot' => '#'.$unit->inventory_lot_id])],
                action: $this->guidance('escalate_inconsistency'),
            )];
        }

        $lotLabel = is_string($lot->lot_number) && $lot->lot_number !== '' ? $lot->lot_number : $this->keyLabel($lot);

        if ($lot->canonical_inventory_lot_id !== null) {
            $fact = $this->fact('serial_lot_merged', ['lot' => $lotLabel]);
        } elseif ((int) $lot->product_variant_id !== (int) $unit->product_variant_id) {
            $fact = $this->fact('serial_lot_other_variant', ['lot' => $lotLabel]);
        } else {
            return [];
        }

        return [$this->finding('serial.lot_mismatch', Category::DataQuality, Severity::Blocker,
            facts: [$fact],
            action: $this->viewLink(InventoryLotResource::class, $lot, 'open_lot'),
        )];
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function conditionFindings(SerializedInventoryUnit $unit): array
    {
        if ($unit->custody_type !== SerializedCustodyType::Warehouse || $unit->warehouse_id === null) {
            return [];
        }

        return match ($unit->stock_condition) {
            StockCondition::Quarantine => [$this->conditionFinding($unit, 'serial.quarantine', InventoryConditionChangeType::QuarantineDisposition, 'decide_quarantine')],
            StockCondition::Damaged => [$this->conditionFinding($unit, 'serial.damaged', InventoryConditionChangeType::DamageRecovery, 'resolve_damage')],
            default => [],
        };
    }

    private function conditionFinding(
        SerializedInventoryUnit $unit,
        string $code,
        InventoryConditionChangeType $type,
        string $labelKey,
    ): InventoryDiagnosticFinding {
        return $this->finding($code, Category::Condition, Severity::Warning,
            facts: [$this->fact('serial_in_warehouse', [
                'warehouse' => $this->warehouseLabel($unit),
                'condition' => $unit->stock_condition->getLabel(),
            ])],
            action: $this->createLink(InventoryConditionChangeResource::class, $labelKey, [
                'type' => $type->value,
                'product_variant_id' => $unit->product_variant_id,
                'warehouse_id' => $unit->warehouse_id,
                'base_quantity' => 1,
            ]),
        );
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function warrantyFindings(SerializedInventoryUnit $unit): array
    {
        $entitlement = $unit->warrantyEntitlements()->first();
        $today = today();

        if (! $entitlement instanceof WarrantyEntitlement) {
            return $this->legacyWarrantyFindings($unit, $today);
        }

        $policyFact = $this->fact('serial_warranty_policy', ['policy' => $entitlement->policy_name]);

        return match (true) {
            $entitlement->state === WarrantyEntitlementState::PendingActivation => [
                $this->finding('serial.warranty_pending_activation', Category::Warranty, Severity::Notice,
                    facts: [$policyFact, $this->fact('serial_warranty_trigger', ['trigger' => $entitlement->start_trigger->label()])],
                    action: $this->activateWarrantyAction(),
                ),
            ],
            $entitlement->state === WarrantyEntitlementState::Cancelled => [
                $this->finding('serial.warranty_cancelled', Category::Warranty, Severity::Notice,
                    facts: [$policyFact, ...$this->endReasonFacts($entitlement)],
                ),
            ],
            $entitlement->state === WarrantyEntitlementState::Ended,
            $entitlement->expires_on instanceof CarbonInterface && $entitlement->expires_on->lt($today) => [
                $this->finding('serial.warranty_ended', Category::Warranty, Severity::Notice,
                    facts: [
                        $policyFact,
                        ...$this->expiryFacts($entitlement->expires_on),
                        ...$this->endReasonFacts($entitlement),
                    ],
                ),
            ],
            $entitlement->isActiveAt($today) && $entitlement->expires_on instanceof CarbonInterface
                && $entitlement->expires_on->lte($today->copy()->addDays(self::WarrantyExpiringDays)) => [
                    $this->expiringFinding($entitlement->expires_on, $today, [$policyFact]),
                ],
            default => [],
        };
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function legacyWarrantyFindings(SerializedInventoryUnit $unit, CarbonInterface $today): array
    {
        $expiresOn = $unit->warranty_expires_on;

        if ($expiresOn instanceof CarbonInterface) {
            $legacyFact = $this->fact('serial_warranty_legacy');

            if ($expiresOn->lt($today)) {
                return [$this->finding('serial.warranty_ended', Category::Warranty, Severity::Notice,
                    facts: [$legacyFact, ...$this->expiryFacts($expiresOn)],
                )];
            }

            return $expiresOn->lte($today->copy()->addDays(self::WarrantyExpiringDays))
                ? [$this->expiringFinding($expiresOn, $today, [$legacyFact])]
                : [];
        }

        $variant = $unit->productVariant;

        if (
            $unit->custody_type === SerializedCustodyType::Customer
            && $variant instanceof ProductVariant
            && $variant->warranty_enabled
        ) {
            return [$this->finding('serial.warranty_missing', Category::Warranty, Severity::Notice,
                facts: [$this->fact('serial_warranty_enabled', ['sku' => $variant->sku])],
            )];
        }

        return [];
    }

    /**
     * @param  list<string>  $facts
     */
    private function expiringFinding(CarbonInterface $expiresOn, CarbonInterface $today, array $facts): InventoryDiagnosticFinding
    {
        $days = (int) $today->copy()->startOfDay()->diffInDays($expiresOn->copy()->startOfDay());

        return $this->finding('serial.warranty_expiring', Category::Warranty, Severity::Notice,
            ['days' => $days, 'date' => $expiresOn->toDateString()],
            [...$facts, ...$this->expiryFacts($expiresOn)],
        );
    }

    private function activateWarrantyAction(): InventoryDiagnosticAction
    {
        $label = $this->actionLabel('serial_activate_warranty', ['button' => (string) __('Activate Warranty')]);

        return (auth()->user()?->can(SupportPermission::WarrantyOverride->value) ?? false)
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function scheduleFindings(SerializedInventoryUnit $unit): array
    {
        $schedules = $unit->maintenanceSchedules()
            ->where('is_active', true)
            ->withCount(['occurrences as missed_occurrences_count' => static fn (Builder $query): Builder => $query->where('status', OccurrenceStatus::Missed->value)])
            ->orderBy('next_due_on')
            ->get(['id', 'schedule_number', 'name', 'next_due_on', 'is_active', 'serialized_inventory_unit_id']);
        $today = today();
        $findings = [];

        $overdue = $schedules->filter(static fn (MaintenanceSchedule $schedule): bool => $schedule->next_due_on->lt($today));
        $overdueFirst = $overdue->first();

        if ($overdueFirst instanceof MaintenanceSchedule) {
            $findings[] = $this->finding('serial.maintenance_overdue', Category::Maintenance, Severity::Warning,
                ['count' => $overdue->count()],
                array_values($overdue->map(fn (MaintenanceSchedule $schedule): string => $this->fact('serial_schedule_due', [
                    'schedule' => $this->scheduleLabel($schedule),
                    'date' => $schedule->next_due_on->toDateString(),
                ]))->all()),
                $this->viewLink(MaintenanceScheduleResource::class, $overdueFirst, 'serial_open_schedule'),
            );
        }

        $missed = $schedules->filter(fn (MaintenanceSchedule $schedule): bool => $this->missedCount($schedule) > 0);
        $missedFirst = $missed->first();

        if ($missedFirst instanceof MaintenanceSchedule) {
            $findings[] = $this->finding('serial.maintenance_missed', Category::Maintenance, Severity::Warning,
                ['count' => (int) $missed->sum(fn (MaintenanceSchedule $schedule): int => $this->missedCount($schedule))],
                array_values($missed->map(fn (MaintenanceSchedule $schedule): string => $this->fact('serial_schedule_missed', [
                    'schedule' => $this->scheduleLabel($schedule),
                    'count' => $this->missedCount($schedule),
                ]))->all()),
                $this->viewLink(MaintenanceScheduleResource::class, $missedFirst, 'serial_open_schedule'),
            );
        }

        return $findings;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function maintenanceRecordFindings(SerializedInventoryUnit $unit): array
    {
        $open = $unit->maintenanceRecords()
            ->whereNotIn('status', [MaintenanceStatus::Closed->value, MaintenanceStatus::Cancelled->value])
            ->latest('id')
            ->get(['id', 'status', 'created_at', 'serialized_inventory_unit_id']);
        $first = $open->first();

        if (! $first instanceof MaintenanceRecord) {
            return [];
        }

        return [$this->finding('serial.maintenance_open', Category::Maintenance, Severity::Notice,
            ['count' => $open->count()],
            array_values($open->map(fn (MaintenanceRecord $job): string => $this->fact('serial_maintenance_request', [
                'request' => $this->keyLabel($job),
                'status' => $job->status->label(),
                'date' => $job->created_at?->toDateString() ?? '—',
            ]))->all()),
            $this->viewLink(MaintenanceRequestResource::class, $first, 'serial_open_maintenance_request'),
        )];
    }

    /**
     * @return list<string>
     */
    private function stateFacts(SerializedInventoryUnit $unit): array
    {
        return [
            $this->fact('serial_state', [
                'status' => $unit->status->getLabel(),
                'custody' => $unit->custody_type->getLabel(),
                'condition' => $unit->stock_condition->getLabel(),
            ]),
            $this->fact('serial_warehouse', ['warehouse' => $this->warehouseLabel($unit)]),
        ];
    }

    /**
     * @return list<string>
     */
    private function referenceFacts(SerializedInventoryUnit $unit): array
    {
        if ($unit->custody_reference_type === null || $unit->custody_reference_id === null) {
            return [$this->fact('serial_no_custody_reference')];
        }

        $key = 'inventory_view_ux.ui.serial_reference_types.'.$unit->custody_reference_type;
        $type = __($key);

        return [$this->fact('serial_custody_reference', [
            'reference' => ($type === $key ? $unit->custody_reference_type : $type).' #'.$unit->custody_reference_id,
        ])];
    }

    /**
     * @return list<string>
     */
    private function expiryFacts(?CarbonInterface $expiresOn): array
    {
        return $expiresOn instanceof CarbonInterface
            ? [$this->fact('serial_warranty_expires', ['date' => $expiresOn->toDateString()])]
            : [];
    }

    /**
     * @return list<string>
     */
    private function endReasonFacts(WarrantyEntitlement $entitlement): array
    {
        return is_string($entitlement->end_reason) && $entitlement->end_reason !== ''
            ? [$this->fact('serial_warranty_end_reason', ['reason' => $entitlement->end_reason])]
            : [];
    }

    private function movementsLink(SerializedInventoryUnit $unit): InventoryDiagnosticAction
    {
        return $this->indexLink(StockMovementResource::class, 'review_movements', $this->filtered([
            'serialized_inventory_unit_id' => $unit->getKey(),
        ]));
    }

    private function operationLabel(InventoryOperation $operation): string
    {
        $number = $operation->operation_number;

        return is_string($number) && $number !== '' ? $number : $this->keyLabel($operation);
    }

    private function missedCount(MaintenanceSchedule $schedule): int
    {
        $count = $schedule->getAttribute('missed_occurrences_count');

        return is_numeric($count) ? (int) $count : 0;
    }

    private function keyLabel(Model $model): string
    {
        $key = $model->getKey();

        return '#'.(is_int($key) || is_string($key) ? $key : '');
    }

    private function scheduleLabel(MaintenanceSchedule $schedule): string
    {
        return $schedule->schedule_number.' — '.$schedule->name;
    }

    private function warehouseLabel(SerializedInventoryUnit $unit): string
    {
        if ($unit->warehouse_id === null) {
            return '—';
        }

        return $unit->warehouse instanceof Warehouse
            ? $this->optionalWarehouseLabel($unit->warehouse)
            : '#'.$unit->warehouse_id;
    }

    private function optionalWarehouseLabel(?Warehouse $warehouse): string
    {
        return $warehouse instanceof Warehouse ? $warehouse->code.' — '.$warehouse->name : '—';
    }
}
