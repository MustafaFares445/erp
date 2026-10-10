<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Enums\AdjustmentStatus;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Filament\Resources\Adjustments\AdjustmentResource;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentItem;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Explains whether a package can still be used by the documents that reference it.
 *
 * Only facts the system enforces are reported: new document lines may select active packages
 * of the document's warehouse only, and {@see InventoryOperationService::markReady()} /
 * {@see InventoryAdjustmentService} reject a package that belongs to another warehouse. What is
 * physically inside the package is not derived here: package movements are not a content balance.
 */
final readonly class PackageDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    /** @var list<OperationStage> */
    private const array OpenStages = [OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready, OperationStage::InTransit];

    /** Stages in which marking the operation ready still checks the package warehouse. */
    private const array UnpreparedStages = [OperationStage::Draft, OperationStage::Waiting];

    public function supports(Model $record): bool
    {
        return $record instanceof Package;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof Package);

        $record->loadMissing(['warehouse:id,code,name,is_active']);
        $operations = $this->openOperations($record);
        $adjustments = $this->draftAdjustments($record);
        $findings = [];

        $typeFinding = $this->typeFinding($record);

        if ($typeFinding instanceof InventoryDiagnosticFinding) {
            $findings[] = $typeFinding;
        }

        if (! $record->is_active && ($operations !== [] || $adjustments !== [])) {
            $findings[] = $this->finding('package.inactive_in_open_documents', Category::DataQuality, Severity::Warning,
                ['count' => count($operations) + count($adjustments)],
                facts: [
                    ...array_map(fn (InventoryOperation $operation): string => $this->fact('package_open_operation', [
                        'document' => $this->operationLabel($operation),
                        'stage' => $operation->stageLabel(),
                    ]), $operations),
                    ...array_map(fn (InventoryAdjustment $adjustment): string => $this->fact('package_draft_adjustment', [
                        'document' => $this->adjustmentLabel($adjustment),
                    ]), $adjustments),
                ],
                action: $this->firstDocumentLink($operations, $adjustments),
            );
        }

        $mismatchedOperations = array_values(array_filter(
            $operations,
            fn (InventoryOperation $operation): bool => in_array($operation->stage, self::UnpreparedStages, true)
                && $this->packageWarehouseOf($operation) !== null
                && $this->packageWarehouseOf($operation) !== $record->warehouse_id,
        ));
        $mismatchedAdjustments = array_values(array_filter(
            $adjustments,
            static fn (InventoryAdjustment $adjustment): bool => $adjustment->warehouse_id !== $record->warehouse_id,
        ));

        if ($mismatchedOperations !== [] || $mismatchedAdjustments !== []) {
            $findings[] = $this->finding('package.warehouse_mismatch', Category::DataQuality, Severity::Blocker,
                ['count' => count($mismatchedOperations) + count($mismatchedAdjustments)],
                facts: [
                    $this->fact('package_located_in', ['warehouse' => $this->warehouseLabel($record->warehouse)]),
                    ...array_map(fn (InventoryOperation $operation): string => $this->fact('package_document_warehouse', [
                        'document' => $this->operationLabel($operation),
                        'warehouse' => $this->warehouseLabel($operation->operation_type === OperationType::Receipt
                            ? $operation->destinationWarehouse
                            : $operation->sourceWarehouse),
                    ]), $mismatchedOperations),
                    ...array_map(fn (InventoryAdjustment $adjustment): string => $this->fact('package_document_warehouse', [
                        'document' => $this->adjustmentLabel($adjustment),
                        'warehouse' => $this->warehouseLabel($adjustment->warehouse),
                    ]), $mismatchedAdjustments),
                ],
                action: $this->firstDocumentLink($mismatchedOperations, $mismatchedAdjustments),
            );
        }

        if ($record->is_active && $record->warehouse instanceof Warehouse && ! $record->warehouse->is_active) {
            $findings[] = $this->finding('package.warehouse_inactive', Category::DataQuality, Severity::Notice,
                ['warehouse' => $this->warehouseLabel($record->warehouse)],
            );
        }

        return new InventoryDiagnosis(
            $findings,
            checks: [
                $this->check('package_type'),
                $this->check('package_open_documents'),
                $this->check('package_document_warehouse'),
                $this->check('package_warehouse_status'),
            ],
            limits: [$this->limit('package_contents')],
        );
    }

    private function typeFinding(Package $package): ?InventoryDiagnosticFinding
    {
        $type = PackageType::query()->withTrashed()->find($package->package_type_id);

        if (! $type instanceof PackageType) {
            return $this->finding('package.type_missing', Category::DataQuality, Severity::Warning,
                action: $this->guidance('package_choose_type'));
        }

        if ($type->trashed()) {
            return $this->finding('package.type_archived', Category::DataQuality, Severity::Warning,
                ['type' => (string) $type->name],
                action: $this->guidance('package_choose_type'));
        }

        if (! $type->is_active) {
            return $this->finding('package.type_inactive', Category::DataQuality, Severity::Notice,
                ['type' => (string) $type->name]);
        }

        return null;
    }

    /** @return list<InventoryOperation> */
    private function openOperations(Package $package): array
    {
        return array_values(InventoryOperation::query()
            ->whereIn('stage', array_map(static fn (OperationStage $stage): string => $stage->value, self::OpenStages))
            ->whereHas('lines', static fn (Builder $line): Builder => $line->where('package_id', $package->getKey()))
            ->with(['sourceWarehouse:id,code,name', 'destinationWarehouse:id,code,name'])
            ->orderBy('id')
            ->get()
            ->all());
    }

    /** @return list<InventoryAdjustment> */
    private function draftAdjustments(Package $package): array
    {
        return array_values(InventoryAdjustment::query()
            ->where('status', AdjustmentStatus::Draft->value)
            ->whereIn('id', InventoryAdjustmentItem::query()
                ->where('package_id', $package->getKey())
                ->select('inventory_adjustment_id'))
            ->with('warehouse:id,code,name')
            ->orderBy('id')
            ->get()
            ->all());
    }

    private function packageWarehouseOf(InventoryOperation $operation): ?int
    {
        $warehouseId = $operation->operation_type === OperationType::Receipt
            ? $operation->destination_warehouse_id
            : $operation->source_warehouse_id;

        return is_int($warehouseId) ? $warehouseId : null;
    }

    /**
     * @param  list<InventoryOperation>  $operations
     * @param  list<InventoryAdjustment>  $adjustments
     */
    private function firstDocumentLink(array $operations, array $adjustments): ?InventoryDiagnosticAction
    {
        if ($operations !== []) {
            return $this->viewLink(InventoryOperationResource::class, $operations[0], 'package_open_document', [
                'document' => $this->operationLabel($operations[0]),
            ]);
        }

        if ($adjustments !== []) {
            return $this->viewLink(AdjustmentResource::class, $adjustments[0], 'package_open_document', [
                'document' => $this->adjustmentLabel($adjustments[0]),
            ]);
        }

        return null;
    }

    private function operationLabel(InventoryOperation $operation): string
    {
        return (string) ($operation->operation_number ?: $operation->operation_type->label().' #'.$operation->getKey());
    }

    private function adjustmentLabel(InventoryAdjustment $adjustment): string
    {
        $number = $adjustment->getAttribute('adjustment_number');

        return is_string($number) && $number !== '' ? $number : '#'.$adjustment->getKey();
    }

    private function warehouseLabel(mixed $warehouse): string
    {
        return $warehouse instanceof Warehouse ? $warehouse->code.' — '.$warehouse->name : '—';
    }
}
