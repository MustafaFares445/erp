<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryConditionChange;
use App\Models\InventoryLotBalance;
use App\Models\InventoryStock;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryConditionChangeService;
use Illuminate\Database\Eloquent\Model;

/**
 * Explains whether a condition-change document can be posted, mirroring the guards of
 * {@see InventoryConditionChangeService::post()}: quarantine dispositions need an active warehouse
 * and enough quarantined stock; disposals need an authoriser other than the creator plus evidence;
 * recoveries may not exceed what remains unrecovered on the damage document they reverse.
 */
final readonly class InventoryConditionChangeDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const float Epsilon = 0.0000005;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryConditionChange;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryConditionChange);

        $record->loadMissing(['warehouse', 'productVariant.unit', 'lot', 'createdBy', 'authorisedBy', 'reversesConditionChange']);

        $findings = match ($record->status) {
            InventoryConditionChangeStatus::Draft => $this->draftFindings($record),
            InventoryConditionChangeStatus::Posted => $this->postedFindings($record),
            InventoryConditionChangeStatus::Cancelled => [$this->finding('condition_change.cancelled', Category::Workflow, Severity::Notice)],
        };

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('condition_change_status'),
                $this->check('condition_change_balance'),
                $this->check('condition_change_disposal'),
                $this->check('condition_change_recovery'),
            ],
            limits: [
                $this->limit('condition_change_tracking'),
                $this->limit('recorded_balances'),
            ],
        );
    }

    /**
     * Quantity of a posted damage document that no posted recovery has reversed yet.
     */
    public static function recoverableQuantity(InventoryConditionChange $damage, ?int $excludeId = null): float
    {
        $recovered = (float) InventoryConditionChange::query()
            ->where('reverses_condition_change_id', $damage->getKey())
            ->where('type', InventoryConditionChangeType::DamageRecovery->value)
            ->where('status', InventoryConditionChangeStatus::Posted->value)
            ->when($excludeId !== null, static fn ($query) => $query->whereKeyNot($excludeId))
            ->sum('base_quantity');

        return (float) $damage->base_quantity - $recovered;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function draftFindings(InventoryConditionChange $change): array
    {
        $findings = match ($change->type) {
            InventoryConditionChangeType::QuarantineDisposition => $this->quarantineFindings($change),
            InventoryConditionChangeType::Damage => $this->damageBalanceFindings($change),
            InventoryConditionChangeType::Disposal => [...$this->disposalFindings($change), ...$this->damageBalanceFindings($change)],
            InventoryConditionChangeType::DamageRecovery => [...$this->recoveryFindings($change), ...$this->damageBalanceFindings($change)],
        };

        $hasBlocker = array_filter($findings, static fn (InventoryDiagnosticFinding $finding): bool => $finding->severity === Severity::Blocker) !== [];

        if (! $hasBlocker) {
            $findings[] = $this->finding('condition_change.ready_to_post', Category::Workflow, Severity::Notice,
                [
                    'quantity' => $this->quantity($change->base_quantity, $this->unit($change)),
                    'from' => $change->condition_from?->getLabel() ?? __('—'),
                    'to' => $change->condition_to?->getLabel() ?? __('—'),
                ],
                action: $this->buttonStep('condition_change_press_button', __('admin.inventory.condition_change.actions.post'),
                    auth()->user()?->can('post', $change) ?? false),
            );
        }

        return $findings;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function quarantineFindings(InventoryConditionChange $change): array
    {
        $findings = [];
        $warehouse = $change->warehouse;

        if (! $warehouse instanceof Warehouse || ! $warehouse->is_active) {
            $findings[] = $this->finding('condition_change.inactive_warehouse', Category::Workflow, Severity::Blocker,
                ['warehouse' => $this->warehouseLabel($change)],
                action: $warehouse instanceof Warehouse ? $this->viewLink(WarehouseResource::class, $warehouse, 'condition_change_open_warehouse') : null,
            );
        }

        $required = (float) $change->base_quantity;
        $unit = $this->unit($change);
        $quarantined = (float) InventoryConditionBalance::query()
            ->where('product_variant_id', $change->product_variant_id)
            ->where('warehouse_id', $change->warehouse_id)
            ->where('stock_condition', StockCondition::Quarantine->value)
            ->value('on_hand_base_quantity');
        $facts = [];

        if ($quarantined + self::Epsilon < $required) {
            $facts[] = $this->fact('condition_change_quarantined', ['quantity' => $this->quantity($quarantined, $unit), 'warehouse' => $this->warehouseLabel($change)]);
        }

        if ($change->inventory_lot_id !== null) {
            $lotQuarantined = (float) InventoryLotBalance::query()
                ->where('inventory_lot_id', $change->inventory_lot_id)
                ->where('warehouse_id', $change->warehouse_id)
                ->where('stock_condition', StockCondition::Quarantine->value)
                ->value('on_hand_base_quantity');

            if ($lotQuarantined + self::Epsilon < $required) {
                $facts[] = $this->fact('condition_change_lot_quarantined', [
                    'lot' => (string) ($change->lot?->lot_number ?? '#'.$change->inventory_lot_id),
                    'quantity' => $this->quantity($lotQuarantined, $unit),
                ]);
            }
        }

        if ($facts !== []) {
            $findings[] = $this->finding('condition_change.insufficient_quarantine', Category::Condition, Severity::Blocker,
                ['quantity' => $this->quantity($required, $unit)],
                [$this->fact('condition_change_required', ['quantity' => $this->quantity($required, $unit)]), ...$facts],
                $this->buttonStep('condition_change_press_button', __('admin.inventory.condition_change.actions.cancel'),
                    auth()->user()?->can('cancel', $change) ?? false),
            );
        }

        return $findings;
    }

    /**
     * Damage, recovery and disposal post through the stock balance of the source condition.
     *
     * @return list<InventoryDiagnosticFinding>
     */
    private function damageBalanceFindings(InventoryConditionChange $change): array
    {
        $from = $change->type->conditionFrom();

        if (! $from instanceof StockCondition) {
            return [];
        }

        $stock = InventoryStock::query()
            ->where('product_variant_id', $change->product_variant_id)
            ->where('warehouse_id', $change->warehouse_id)
            ->first();

        if (! $stock instanceof InventoryStock) {
            return [$this->finding('condition_change.no_stock', Category::Availability, Severity::Blocker,
                ['warehouse' => $this->warehouseLabel($change)],
            )];
        }

        $unit = $this->unit($change);
        $onHand = $stock->conditionOnHandQuantity($from);

        if ($onHand + self::Epsilon >= (float) $change->base_quantity) {
            return [];
        }

        return [$this->finding('condition_change.insufficient_balance', Category::Condition, Severity::Warning,
            ['condition' => $from->getLabel()],
            [
                $this->fact('condition_change_required', ['quantity' => $this->quantity($change->base_quantity, $unit)]),
                $this->fact('condition_change_condition_on_hand', ['condition' => $from->getLabel(), 'quantity' => $this->quantity($onHand, $unit)]),
            ],
        )];
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function disposalFindings(InventoryConditionChange $change): array
    {
        $findings = [];
        $cancel = $this->buttonStep('condition_change_redraft_disposal', __('admin.inventory.condition_change.actions.cancel'),
            auth()->user()?->can('cancel', $change) ?? false);

        if ($change->authorised_by === null) {
            $findings[] = $this->finding('condition_change.authoriser_missing', Category::Approval, Severity::Blocker, action: $cancel);
        } elseif ((int) $change->authorised_by === (int) $change->created_by) {
            $findings[] = $this->finding('condition_change.authoriser_is_creator', Category::Approval, Severity::Blocker,
                ['name' => (string) ($change->authorisedBy?->name ?? '#'.$change->authorised_by)],
                action: $cancel,
            );
        }

        if ($change->getMedia('disposal-evidence')->isEmpty()) {
            $findings[] = $this->finding('condition_change.evidence_missing', Category::Approval, Severity::Blocker,
                action: $this->buttonStep('condition_change_press_button', __('admin.inventory.condition_change.actions.attach_evidence'),
                    auth()->user()?->can('create', InventoryConditionChange::class) ?? false),
            );
        }

        return $findings;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function recoveryFindings(InventoryConditionChange $change): array
    {
        $damage = $change->reversesConditionChange;

        if (! $damage instanceof InventoryConditionChange || $damage->type !== InventoryConditionChangeType::Damage) {
            return [$this->finding('condition_change.invalid_reversal', Category::DataQuality, Severity::Blocker)];
        }

        $remaining = self::recoverableQuantity($damage, (int) $change->getKey());

        if ((float) $change->base_quantity <= $remaining + self::Epsilon) {
            return [];
        }

        $unit = $this->unit($change);

        return [$this->finding('condition_change.recovery_exceeds', Category::Condition, Severity::Blocker,
            ['document' => (string) $damage->document_number],
            [
                $this->fact('condition_change_required', ['quantity' => $this->quantity($change->base_quantity, $unit)]),
                $this->fact('condition_change_recoverable', ['document' => (string) $damage->document_number, 'quantity' => $this->quantity(max(0.0, $remaining), $unit)]),
            ],
            $this->viewLink(InventoryConditionChangeResource::class, $damage, 'condition_change_open_damage'),
        )];
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function postedFindings(InventoryConditionChange $change): array
    {
        if ($change->type !== InventoryConditionChangeType::Damage) {
            return [];
        }

        $remaining = self::recoverableQuantity($change);

        if ($remaining <= self::Epsilon) {
            return [];
        }

        return [$this->finding('condition_change.damage_open', Category::Condition, Severity::Notice,
            ['quantity' => $this->quantity($remaining, $this->unit($change))],
            action: $this->createLink(InventoryConditionChangeResource::class, 'resolve_damage'),
        )];
    }

    private function buttonStep(string $key, string $button, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($key, ['button' => $button]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function warehouseLabel(InventoryConditionChange $change): string
    {
        $warehouse = $change->warehouse;

        return $warehouse instanceof Warehouse ? $warehouse->code.' — '.$warehouse->name : '#'.$change->warehouse_id;
    }

    private function unit(InventoryConditionChange $change): ?string
    {
        $unit = $change->productVariant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
