<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\StockCondition;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains why an inventory adjustment can or cannot be confirmed, mirroring the guards of
 * {@see InventoryAdjustmentService::confirm()} that can be evaluated from stored data
 * (status, maker-checker, active warehouse, lines, operational variants, conditions, quantities,
 * lot/serial/package identity). Live-balance and serial-custody checks stay with the service.
 */
final readonly class InventoryAdjustmentDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const int FactLimit = 5;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryAdjustment;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryAdjustment);

        $record->loadMissing([
            'warehouse',
            'createdBy:id,name',
            'correctsAdjustment',
            'items.productVariant.product',
            'items.productVariant.unit',
            'items.lot:id,lot_number',
            'items.package:id,name,warehouse_id',
        ]);
        $record->loadCount('corrections');

        $findings = [];

        if ($record->correctsAdjustment instanceof InventoryAdjustment) {
            $findings[] = $this->finding('adjustment.corrects', Category::Workflow, Severity::Notice,
                ['number' => $this->number($record->correctsAdjustment)],
                action: $this->viewLink(AdjustmentResource::class, $record->correctsAdjustment, 'adjustment_open_original'),
            );
        }

        $findings = $record->isDraft()
            ? [...$findings, ...$this->draftFindings($record)]
            : [...$findings, ...$this->confirmedFindings($record)];

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('adjustment_status'),
                $this->check('adjustment_warehouse'),
                $this->check('adjustment_lines'),
                $this->check('adjustment_tracking'),
                $this->check('adjustment_maker_checker'),
            ],
            limits: [
                $this->limit('adjustment_live_quantity'),
                $this->limit('recorded_balances'),
            ],
        );
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function draftFindings(InventoryAdjustment $adjustment): array
    {
        $items = $adjustment->items;
        $editAction = InventoryDiagnosticAction::link(
            $this->actionLabel('adjustment_edit_lines'),
            AdjustmentResource::getUrl('edit', ['record' => $adjustment]),
            AdjustmentResource::canEdit($adjustment),
        );
        $findings = [];

        $warehouse = $adjustment->warehouse;

        if (! $warehouse instanceof Warehouse || ! $warehouse->is_active) {
            $findings[] = $this->finding('adjustment.inactive_warehouse', Category::Workflow, Severity::Blocker,
                ['warehouse' => $this->warehouseLabel($adjustment)],
                action: $warehouse instanceof Warehouse
                    ? $this->viewLink(WarehouseResource::class, $warehouse, 'adjustment_open_warehouse')
                    : null,
            );
        }

        if ($items->isEmpty()) {
            $findings[] = $this->finding('adjustment.empty', Category::Workflow, Severity::Blocker, action: $editAction);
        }

        $lineRules = [
            'adjustment.inactive_variant' => static fn (InventoryAdjustmentItem $item): bool => ! $item->productVariant instanceof ProductVariant
                || ! $item->productVariant->isOperational(),
            'adjustment.disposed_line' => static fn (InventoryAdjustmentItem $item): bool => $item->stock_condition === StockCondition::Disposed,
            'adjustment.negative_quantity' => static fn (InventoryAdjustmentItem $item): bool => (float) $item->new_quantity < 0,
            'adjustment.lot_missing' => static fn (InventoryAdjustmentItem $item): bool => $item->productVariant instanceof ProductVariant
                && $item->productVariant->tracksLotsConfigured()
                && $item->inventory_lot_id === null,
            'adjustment.serial_invalid' => static fn (InventoryAdjustmentItem $item): bool => $item->productVariant instanceof ProductVariant
                && ($item->productVariant->tracksSerialsConfigured() || $item->serialized_inventory_unit_id !== null)
                && ($item->serialized_inventory_unit_id === null || ! in_array((float) $item->new_quantity, [0.0, 1.0], true)),
            'adjustment.package_mismatch' => static fn (InventoryAdjustmentItem $item): bool => $item->package_id !== null
                && (! $item->package instanceof Package || (int) $item->package->warehouse_id !== (int) $adjustment->warehouse_id),
        ];

        foreach ($lineRules as $code => $rule) {
            $affected = $items->filter($rule)->values();

            if ($affected->isNotEmpty()) {
                $findings[] = $this->finding($code, $code === 'adjustment.inactive_variant' ? Category::DataQuality : Category::Tracking, Severity::Blocker,
                    ['count' => $affected->count()],
                    $this->lineFacts($affected),
                    $editAction,
                );
            }
        }

        $hasBlocker = $findings !== [];
        $actorId = auth()->id();
        $makerId = is_numeric($adjustment->created_by) ? (int) $adjustment->created_by : null;
        $isMaker = $makerId !== null && $actorId !== null && (int) $actorId === $makerId;

        if ($isMaker) {
            $findings[] = $this->finding('adjustment.self_confirm', Category::Approval, Severity::Notice,
                action: $this->guidance('adjustment_ask_checker'),
            );
        } elseif (! $hasBlocker) {
            $findings[] = $this->finding('adjustment.ready_to_confirm', Category::Approval, Severity::Notice,
                ['count' => $items->count()],
                facts: $this->createdByFacts($adjustment),
                action: $this->buttonStep('adjustment_press_confirm', __('admin.inventory.adjustment.confirm'),
                    auth()->user()?->can('confirm', $adjustment) ?? false),
            );
        }

        return $findings;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function confirmedFindings(InventoryAdjustment $adjustment): array
    {
        $changed = $adjustment->items->filter(static fn (InventoryAdjustmentItem $item): bool => abs((float) $item->difference) > 0.0000001)->values();

        $findings = [$this->finding('adjustment.confirmed', Category::Workflow, Severity::Notice,
            ['number' => $this->number($adjustment), 'count' => $changed->count()],
            [
                $this->fact('adjustment_net_increase', ['quantity' => $this->quantity(self::netIncrease($adjustment))]),
                $this->fact('adjustment_net_decrease', ['quantity' => $this->quantity(self::netDecrease($adjustment))]),
                ...$this->lineFacts($changed, fn (InventoryAdjustmentItem $item): string => $this->fact('adjustment_line_change', [
                    'sku' => $this->sku($item),
                    'condition' => $item->stock_condition->getLabel(),
                    'old' => $this->quantity($item->old_quantity, $this->unit($item)),
                    'new' => $this->quantity($item->new_quantity, $this->unit($item)),
                    'difference' => $this->signed((float) $item->difference, $this->unit($item)),
                ])),
            ],
        )];

        $corrections = (int) $adjustment->getAttribute('corrections_count');

        if ($corrections > 0) {
            $findings[] = $this->finding('adjustment.corrected', Category::Workflow, Severity::Notice, ['count' => $corrections]);
        }

        return $findings;
    }

    public static function netIncrease(InventoryAdjustment $adjustment): float
    {
        return (float) $adjustment->items->sum(static fn (InventoryAdjustmentItem $item): float => max(0.0, (float) $item->difference));
    }

    public static function netDecrease(InventoryAdjustment $adjustment): float
    {
        return (float) $adjustment->items->sum(static fn (InventoryAdjustmentItem $item): float => min(0.0, (float) $item->difference));
    }

    /**
     * @param  Collection<int, InventoryAdjustmentItem>  $items
     * @param  (Closure(InventoryAdjustmentItem): string)|null  $format
     * @return list<string>
     */
    private function lineFacts(Collection $items, ?Closure $format = null): array
    {
        $format ??= fn (InventoryAdjustmentItem $item): string => $this->fact('adjustment_line', [
            'sku' => $this->sku($item),
            'condition' => $item->stock_condition->getLabel(),
            'quantity' => $this->quantity($item->new_quantity, $this->unit($item)),
        ]);

        $facts = $items->take(self::FactLimit)->map($format)->values()->all();

        if ($items->count() > self::FactLimit) {
            $facts[] = $this->fact('adjustment_more_lines', ['count' => $items->count() - self::FactLimit]);
        }

        return $facts;
    }

    /**
     * @return list<string>
     */
    private function createdByFacts(InventoryAdjustment $adjustment): array
    {
        $maker = $adjustment->createdBy;

        return $maker === null ? [] : [$this->fact('adjustment_prepared_by', ['name' => (string) $maker->name])];
    }

    private function buttonStep(string $key, string $button, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($key, ['button' => $button]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function number(InventoryAdjustment $adjustment): string
    {
        return is_string($adjustment->adjustment_number) && $adjustment->adjustment_number !== ''
            ? $adjustment->adjustment_number
            : '#'.$adjustment->getKey();
    }

    private function warehouseLabel(InventoryAdjustment $adjustment): string
    {
        $warehouse = $adjustment->warehouse;

        return $warehouse instanceof Warehouse ? $warehouse->code.' — '.$warehouse->name : '#'.$adjustment->warehouse_id;
    }

    private function sku(InventoryAdjustmentItem $item): string
    {
        $sku = (string) ($item->productVariant?->sku ?? '#'.$item->product_variant_id);

        if ($item->lot !== null) {
            return $sku.' / '.$item->lot->lot_number;
        }

        return $sku;
    }

    private function unit(InventoryAdjustmentItem $item): ?string
    {
        $unit = $item->productVariant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }

    private function signed(float $quantity, ?string $unit): string
    {
        return ($quantity > 0 ? '+' : '').$this->quantity($quantity, $unit);
    }
}
