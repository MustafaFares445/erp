<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\InventoryCorrectionStatus;
use App\Enums\InventoryCorrectionType;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\InventoryReturnStatus;
use App\Enums\InventoryReturnType;
use App\Enums\OperationStage;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryCorrection;
use App\Models\InventoryCorrectionLine;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryReturnLine;
use App\Models\Unit;
use App\Services\Inventory\InventoryCorrectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Explains whether a draft inventory correction can be posted, mirroring the guards of
 * {@see InventoryCorrectionService::post()}: the original operation must still be a completed
 * operation of the matching type, the document needs lines, each line may only reverse saleable
 * postings, serialized lines exactly one unit, and no line may exceed what remains correctable
 * after posted corrections (and, for deliveries, posted customer returns).
 */
final readonly class InventoryCorrectionDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const int FactLimit = 5;

    private const float Epsilon = 0.0000005;

    public function supports(Model $record): bool
    {
        return $record instanceof InventoryCorrection;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof InventoryCorrection);

        $record->loadMissing(['originalOperation', 'lines.productVariant.unit', 'lines.originalMovement', 'lines.lot:id,lot_number']);

        $findings = match ($record->status) {
            InventoryCorrectionStatus::Draft => $this->draftFindings($record),
            InventoryCorrectionStatus::Posted => [],
            InventoryCorrectionStatus::Cancelled => [$this->finding('correction.cancelled', Category::Workflow, Severity::Notice,
                facts: [$this->fact('correction_cancellation_reason', ['reason' => $record->cancellation_reason ?: __('—')])],
            )],
        };

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('correction_status'),
                $this->check('correction_original'),
                $this->check('correction_lines'),
                $this->check('correction_remaining'),
            ],
            limits: [
                $this->limit('correction_serial_custody'),
                $this->limit('recorded_balances'),
            ],
        );
    }

    /**
     * Remaining correctable base quantity per original movement id, excluding this document.
     *
     * @return array<int, float>
     */
    public static function remainingByMovement(InventoryCorrection $correction): array
    {
        $correction->loadMissing('lines.originalMovement');
        $movementIds = $correction->lines->pluck('original_inventory_movement_id')->filter()->unique()->values()->all();

        if ($movementIds === []) {
            return [];
        }

        $postedCorrections = InventoryCorrectionLine::query()
            ->whereIn('original_inventory_movement_id', $movementIds)
            ->whereHas('correction', static fn (Builder $query): Builder => $query->where('status', InventoryCorrectionStatus::Posted->value))
            ->selectRaw('original_inventory_movement_id, SUM(posted_base_quantity) as posted_total')
            ->groupBy('original_inventory_movement_id')
            ->pluck('posted_total', 'original_inventory_movement_id');

        $postedReturns = $correction->correction_type === InventoryCorrectionType::Delivery
            ? InventoryReturnLine::query()
                ->whereIn('original_inventory_movement_id', $movementIds)
                ->whereHas('inventoryReturn', static fn (Builder $query): Builder => $query
                    ->where('return_type', InventoryReturnType::Customer->value)
                    ->where('status', InventoryReturnStatus::Posted->value))
                ->selectRaw('original_inventory_movement_id, SUM(posted_base_quantity) as posted_total')
                ->groupBy('original_inventory_movement_id')
                ->pluck('posted_total', 'original_inventory_movement_id')
            : collect();

        $remaining = [];

        foreach ($correction->lines as $line) {
            $movement = $line->originalMovement;

            if (! $movement instanceof InventoryMovement || isset($remaining[$movement->getKey()])) {
                continue;
            }

            $original = abs((float) ($movement->base_quantity_delta ?? $movement->quantity));
            $remaining[(int) $movement->getKey()] = $original
                - (float) ($postedCorrections[$movement->getKey()] ?? 0)
                - (float) ($postedReturns[$movement->getKey()] ?? 0);
        }

        return $remaining;
    }

    /**
     * @return list<InventoryDiagnosticFinding>
     */
    private function draftFindings(InventoryCorrection $correction): array
    {
        $findings = [];
        $operation = $correction->originalOperation;
        $expectedType = $correction->correction_type->originOperationType();

        if (! $operation instanceof InventoryOperation || $operation->operation_type !== $expectedType) {
            $findings[] = $this->finding('correction.original_mismatch', Category::DataQuality, Severity::Blocker);
        } elseif ($operation->stage === OperationStage::PartiallyReceived) {
            $findings[] = $this->finding('correction.transfer_partially_received', Category::Transfer, Severity::Blocker,
                ['operation' => (string) $operation->operation_number],
                action: $this->viewLink(InventoryOperationResource::class, $operation, 'open_transfer'),
            );
        } elseif ($operation->stage !== OperationStage::Done) {
            $findings[] = $this->finding('correction.original_not_done', Category::Workflow, Severity::Blocker,
                ['operation' => (string) $operation->operation_number, 'stage' => $operation->stage->label()],
                action: $this->viewLink(InventoryOperationResource::class, $operation, 'correction_open_original'),
            );
        }

        $lines = $correction->lines;

        if ($lines->isEmpty()) {
            $findings[] = $this->finding('correction.empty', Category::Workflow, Severity::Blocker,
                action: $this->buttonStep('correction_add_lines', __('admin.inventory.correction.actions.add_line'),
                    auth()->user()?->can('update', $correction) ?? false),
            );
        }

        $findings = [...$findings, ...$this->lineFindings($correction, $lines)];

        if ($findings === []) {
            $findings[] = $this->finding('correction.ready_to_post', Category::Workflow, Severity::Notice,
                ['count' => $lines->count()],
                action: $this->buttonStep('correction_press_button', __('admin.inventory.correction.actions.post'),
                    auth()->user()?->can('post', $correction) ?? false),
            );
        }

        return $findings;
    }

    /**
     * @param  Collection<int, InventoryCorrectionLine>  $lines
     * @return list<InventoryDiagnosticFinding>
     */
    private function lineFindings(InventoryCorrection $correction, Collection $lines): array
    {
        $findings = [];
        $missing = $lines->filter(static fn (InventoryCorrectionLine $line): bool => ! $line->originalMovement instanceof InventoryMovement)->values();

        if ($missing->isNotEmpty()) {
            $findings[] = $this->finding('correction.movement_missing', Category::DataQuality, Severity::Blocker,
                ['count' => $missing->count()], $this->lineFacts($missing));
        }

        $isDelivery = $correction->correction_type === InventoryCorrectionType::Delivery;
        $notSaleable = $lines->filter(static function (InventoryCorrectionLine $line) use ($isDelivery): bool {
            $movement = $line->originalMovement;

            if (! $movement instanceof InventoryMovement) {
                return false;
            }

            $condition = $isDelivery ? $movement->stock_condition_from : $movement->stock_condition_to;

            return $condition !== null && $condition !== StockCondition::Saleable;
        })->values();

        if ($notSaleable->isNotEmpty()) {
            $findings[] = $this->finding('correction.not_saleable', Category::Condition, Severity::Blocker,
                ['count' => $notSaleable->count()], $this->lineFacts($notSaleable));
        }

        $serial = $lines->filter(static fn (InventoryCorrectionLine $line): bool => $line->originalMovement instanceof InventoryMovement
            && is_int($line->originalMovement->serialized_inventory_unit_id)
            && abs((float) $line->base_quantity - 1.0) > self::Epsilon)->values();

        if ($serial->isNotEmpty()) {
            $findings[] = $this->finding('correction.serial_quantity', Category::Tracking, Severity::Blocker,
                ['count' => $serial->count()], $this->lineFacts($serial));
        }

        $remaining = self::remainingByMovement($correction);
        $facts = [];

        foreach ($lines as $line) {
            $movementId = $line->original_inventory_movement_id;

            if (! is_int($movementId) || ! array_key_exists($movementId, $remaining)) {
                continue;
            }

            if ((float) $line->base_quantity > $remaining[$movementId] + self::Epsilon) {
                $facts[] = $this->fact('correction_line_exceeds', [
                    'sku' => $this->sku($line),
                    'requested' => $this->quantity($line->base_quantity, $this->unit($line)),
                    'remaining' => $this->quantity(max(0.0, $remaining[$movementId]), $this->unit($line)),
                ]);
            }
        }

        if ($facts !== []) {
            $findings[] = $this->finding('correction.exceeds_remaining', Category::Workflow, Severity::Blocker,
                ['count' => count($facts)], $facts);
        }

        return $findings;
    }

    /**
     * @param  Collection<int, InventoryCorrectionLine>  $lines
     * @return list<string>
     */
    private function lineFacts(Collection $lines): array
    {
        $facts = $lines->take(self::FactLimit)
            ->map(fn (InventoryCorrectionLine $line): string => $this->fact('correction_line', [
                'sku' => $this->sku($line),
                'quantity' => $this->quantity($line->base_quantity, $this->unit($line)),
            ]))
            ->values()
            ->all();

        if ($lines->count() > self::FactLimit) {
            $facts[] = $this->fact('correction_more_lines', ['count' => $lines->count() - self::FactLimit]);
        }

        return $facts;
    }

    private function buttonStep(string $key, string $button, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($key, ['button' => $button]);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function sku(InventoryCorrectionLine $line): string
    {
        $sku = (string) ($line->productVariant?->sku ?? '#'.$line->product_variant_id);

        return $line->lot !== null ? $sku.' / '.$line->lot->lot_number : $sku;
    }

    private function unit(InventoryCorrectionLine $line): ?string
    {
        $unit = $line->productVariant?->unit;

        return $unit instanceof Unit ? ($unit->symbol ?: $unit->code) : null;
    }
}
