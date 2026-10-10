<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryConditionChange;
use App\Models\InventoryCorrection;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryReturn;
use App\Models\Unit;
use App\Services\Inventory\InventoryPostingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Explains one immutable ledger row. A movement is never a problem by itself: the panel says
 * whether it reverses or was reversed by another movement, whether its source document still
 * exists, and whether it moved stock between conditions rather than in or out of the warehouse.
 * The only Blocker is a ledger contradiction (reversals larger than the original movement),
 * which {@see InventoryPostingService} is meant to prevent.
 */
final readonly class StockMovementDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const float Tolerance = 0.000001;

    /**
     * Inventory source documents whose existence can be verified. Other source types (sales,
     * finance, service records) are owned by other domains and listed under the limits.
     *
     * @var array<string, class-string<Model>>
     */
    private const array SourceModels = [
        'inventory_operation' => InventoryOperation::class,
        'adjustment' => InventoryAdjustment::class,
        'inventory_correction' => InventoryCorrection::class,
        'inventory_return' => InventoryReturn::class,
        'inventory_condition_change' => InventoryConditionChange::class,
        'inventory_reservation' => InventoryReservation::class,
    ];

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryMovement;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryMovement);

        $record->loadMissing(['reversals', 'productVariant.unit']);
        $unit = $this->unitSymbol($record);
        $findings = [];

        // Loaded in full: the resource eager-loads `reversalOf` with a reduced column list.
        $original = $record->reversal_of_movement_id === null ? null : InventoryMovement::query()->find($record->reversal_of_movement_id);

        if ($original instanceof InventoryMovement) {
            $findings[] = $this->finding('movement.is_reversal', Category::Workflow, Severity::Notice,
                ['movement' => '#'.$original->getKey()],
                facts: [
                    $this->fact('movement_original', [
                        'movement' => '#'.$original->getKey(),
                        'date' => $original->created_at?->toDateTimeString() ?? '—',
                        'quantity' => $this->quantity(self::signedQuantity($original), $unit),
                    ]),
                    $this->fact('movement_this_quantity', ['quantity' => $this->quantity(self::signedQuantity($record), $unit)]),
                ],
                action: $this->viewLink(StockMovementResource::class, $original, 'movement_open_original'),
            );
        }

        $findings = [...$findings, ...$this->reversedFindings($record, $unit)];

        $sourceFinding = $this->sourceFinding($record);

        if ($sourceFinding instanceof InventoryDiagnosticFinding) {
            $findings[] = $sourceFinding;
        }

        if ($record->stock_condition_from !== null
            && $record->stock_condition_to !== null
            && $record->stock_condition_from !== $record->stock_condition_to) {
            $findings[] = $this->finding('movement.condition_change', Category::Condition, Severity::Notice,
                [
                    'from' => $record->stock_condition_from->getLabel(),
                    'to' => $record->stock_condition_to->getLabel(),
                ],
                facts: [
                    $this->fact('movement_condition_balance', [
                        'condition' => $record->stock_condition_from->getLabel(),
                        'before' => $this->quantity($record->condition_from_on_hand_before, $unit),
                        'after' => $this->quantity($record->condition_from_on_hand_after, $unit),
                    ]),
                    $this->fact('movement_condition_balance', [
                        'condition' => $record->stock_condition_to->getLabel(),
                        'before' => $this->quantity($record->condition_to_on_hand_before, $unit),
                        'after' => $this->quantity($record->condition_to_on_hand_after, $unit),
                    ]),
                ],
            );
        }

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('movement_reversal_link'),
                $this->check('movement_reversed_by'),
                $this->check('movement_source_document'),
                $this->check('movement_condition'),
            ],
            limits: [
                $this->limit('movement_immutable'),
                $this->limit('movement_foreign_sources'),
            ],
        );
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function reversedFindings(InventoryMovement $movement, ?string $unit): array
    {
        $reversals = $movement->reversals->sortBy('id')->values();
        $first = $reversals->first();

        if (! $first instanceof InventoryMovement) {
            return [];
        }

        $original = self::signedQuantity($movement);
        $reversed = (float) $reversals->sum(static fn (InventoryMovement $reversal): float => self::signedQuantity($reversal));
        $facts = [
            ...$reversals->map(fn (InventoryMovement $reversal): string => $this->fact('movement_reversal', [
                'movement' => '#'.$reversal->getKey(),
                'date' => $reversal->created_at?->toDateTimeString() ?? '—',
                'quantity' => $this->quantity(self::signedQuantity($reversal), $unit),
            ]))->all(),
            $this->fact('movement_net_effect', ['quantity' => $this->quantity($original + $reversed, $unit)]),
        ];
        $action = $this->viewLink(StockMovementResource::class, $first, 'movement_open_reversal', ['movement' => '#'.$first->getKey()]);

        if (abs($reversed) > abs($original) + self::Tolerance) {
            return [$this->finding('movement.over_reversed', Category::DataQuality, Severity::Blocker,
                ['original' => $this->quantity($original, $unit), 'reversed' => $this->quantity($reversed, $unit)],
                [$this->fact('movement_this_quantity', ['quantity' => $this->quantity($original, $unit)]), ...$facts],
                $this->guidance('escalate_inconsistency'),
            )];
        }

        $fully = abs($original + $reversed) <= self::Tolerance;

        return [$this->finding($fully ? 'movement.reversed' : 'movement.partially_reversed', Category::Workflow, Severity::Notice,
            ['count' => $reversals->count()],
            $facts,
            $action,
        )];
    }

    private function sourceFinding(InventoryMovement $movement): ?InventoryDiagnosticFinding
    {
        $type = $movement->getAttribute('source_type');
        $id = $movement->getAttribute('source_id');

        if (! is_string($type) || $type === '' || $id === null) {
            return $this->finding('movement.no_source', Category::DataQuality, Severity::Notice);
        }

        $class = self::SourceModels[$type] ?? null;

        if ($class === null) {
            return null;
        }

        $softDeletes = in_array(SoftDeletes::class, class_uses_recursive($class), true);
        $query = $class::query();

        if ($softDeletes) {
            /** @phpstan-ignore method.notFound (the trait is checked above) */
            $query->withTrashed();
        }

        $source = $query->find($id);
        $replace = ['type' => $type, 'id' => (string) $id];

        if (! $source instanceof Model) {
            return $this->finding('movement.source_missing', Category::DataQuality, Severity::Warning, $replace,
                action: $this->guidance('escalate_inconsistency'));
        }

        if ($softDeletes && $source->getAttribute('deleted_at') !== null) {
            return $this->finding('movement.source_deleted', Category::DataQuality, Severity::Warning,
                [...$replace, 'date' => (string) $source->getAttribute('deleted_at')],
                action: $this->guidance('movement_restore_source'));
        }

        return null;
    }

    private static function signedQuantity(InventoryMovement $movement): float
    {
        return (float) ($movement->base_quantity_delta ?? $movement->quantity);
    }

    private function unitSymbol(InventoryMovement $movement): ?string
    {
        $unit = $movement->productVariant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
