<?php

declare(strict_types=1);

namespace App\Services\Inventory\Diagnostics;

use App\Data\Inventory\InventoryDiagnosis;
use App\Data\Inventory\InventoryDiagnosticAction;
use App\Data\Inventory\InventoryDiagnosticFinding;
use App\Data\Inventory\LogisticsInboundAllocationData;
use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundData;
use App\Data\Inventory\LogisticsInboundLineData;
use App\Enums\InventoryDiagnosticCategory as Category;
use App\Enums\InventoryDiagnosticSeverity as Severity;
use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\PurchaseInboundStatus;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Illuminate\Database\Eloquent\Model;

/**
 * Explains where a purchase inbound stands between the supplier commitment, the warehouse
 * allocation and the completed receipts. Reuses the read-only
 * {@see LogisticsInboundProjectionService} (the same numbers and blockers the page shows), so
 * this class adds the evidence and next step, never a second calculation.
 */
final readonly class PurchaseInboundDiagnoser implements InventoryRecordDiagnoser
{
    use BuildsInventoryFindings;

    private const float Tolerance = 0.000001;

    /** @var list<OperationStage> */
    private const array OpenReceiptStages = [OperationStage::Draft, OperationStage::Waiting, OperationStage::Ready];

    public function __construct(private LogisticsInboundProjectionService $projections) {}

    public function supports(Model $record): bool
    {
        return $record instanceof PurchaseInbound;
    }

    public function diagnose(Model $record): InventoryDiagnosis
    {
        assert($record instanceof PurchaseInbound);

        $projection = $this->projections->project($record);
        $checks = [
            $this->check('inbound_supplier_commitment'),
            $this->check('inbound_allocation'),
            $this->check('inbound_receipts'),
            $this->check('inbound_received_quantities'),
            $this->check('inbound_expected_date'),
        ];
        $limits = [$this->limit('inbound_physical_arrival'), $this->limit('unposted_documents')];

        if ($record->status === PurchaseInboundStatus::Cancelled) {
            return new InventoryDiagnosis(
                [$this->finding('inbound.cancelled', Category::Receipt, Severity::Notice)],
                $checks,
                $limits,
            );
        }

        $findings = [
            ...$this->supplierFindings($record, $projection),
            ...$this->allocationFindings($projection),
            ...$this->receiptFindings($record, $projection),
        ];

        if ($projection->overdue) {
            $findings[] = $this->finding('inbound.overdue', Category::Receipt, Severity::Warning,
                ['days' => (int) $projection->expectedAt?->copy()->startOfDay()->diffInDays(now()->startOfDay())],
                facts: [
                    $this->fact('inbound_expected_on', ['date' => $projection->expectedAt?->toDateString() ?? '—']),
                    $this->fact('inbound_remaining', ['quantity' => $this->quantity($projection->remainingBaseQuantity)]),
                ],
                action: $this->purchaseOrderLink($record),
            );
        }

        return new InventoryDiagnosis($findings, $checks, $limits);
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function supplierFindings(PurchaseInbound $inbound, LogisticsInboundData $projection): array
    {
        $awaiting = [];
        $shortfalls = [];

        foreach ($projection->lines as $line) {
            if ($this->hasBlocker($line, 'awaiting_supplier_confirmation')) {
                $awaiting[] = $this->fact('inbound_line_awaiting_confirmation', [
                    'sku' => $line->sku,
                    'ordered' => $this->quantity($line->orderedBaseQuantity, $line->uom),
                ]);

                continue;
            }

            if ((float) $line->confirmedBaseQuantity + self::Tolerance < (float) $line->orderedBaseQuantity
                || (float) $line->backorderedBaseQuantity > self::Tolerance) {
                $shortfalls[] = $this->fact('inbound_line_shortfall', [
                    'sku' => $line->sku,
                    'ordered' => $this->quantity($line->orderedBaseQuantity, $line->uom),
                    'confirmed' => $this->quantity($line->confirmedBaseQuantity, $line->uom),
                    'backordered' => $this->quantity($line->backorderedBaseQuantity, $line->uom),
                ]);
            }
        }

        $findings = [];

        if ($awaiting !== []) {
            $findings[] = $this->finding('inbound.awaiting_confirmation', Category::Receipt, Severity::Notice,
                ['count' => count($awaiting)], $awaiting, $this->purchaseOrderLink($inbound));
        }

        if ($shortfalls !== []) {
            $findings[] = $this->finding('inbound.supplier_shortfall', Category::Receipt, Severity::Warning,
                ['count' => count($shortfalls)], $shortfalls, $this->purchaseOrderLink($inbound));
        }

        return $findings;
    }

    /** @return list<InventoryDiagnosticFinding> */
    private function allocationFindings(LogisticsInboundData $projection): array
    {
        $overAllocated = [];
        $toAllocate = [];
        $notOnReceipt = [];
        $overReceived = [];

        foreach ($projection->lines as $line) {
            if ($this->hasBlocker($line, 'over_allocated')) {
                $overAllocated[] = $this->fact('inbound_line_over_allocated', [
                    'sku' => $line->sku,
                    'confirmed' => $this->quantity($line->confirmedBaseQuantity, $line->uom),
                    'allocated' => $this->quantity($line->allocatedBaseQuantity, $line->uom),
                ]);
            }

            if ((float) $line->currentlyAllocatableBaseQuantity > self::Tolerance) {
                $toAllocate[] = $this->fact('inbound_line_to_allocate', [
                    'sku' => $line->sku,
                    'quantity' => $this->quantity($line->currentlyAllocatableBaseQuantity, $line->uom),
                ]);
            }

            foreach ($line->allocations as $allocation) {
                if ((float) $allocation->availableToReceive > self::Tolerance) {
                    $notOnReceipt[] = $this->allocationFact($line, $allocation);
                }
            }

            if ((float) $line->receivedBaseQuantity > (float) $line->confirmedBaseQuantity + self::Tolerance) {
                $overReceived[] = $this->fact('inbound_line_over_received', [
                    'sku' => $line->sku,
                    'confirmed' => $this->quantity($line->confirmedBaseQuantity, $line->uom),
                    'received' => $this->quantity($line->receivedBaseQuantity, $line->uom),
                ]);
            }
        }

        $canAllocate = $this->can(InventoryPermission::InboundAllocate);
        $findings = [];

        if ($overAllocated !== []) {
            $findings[] = $this->finding('inbound.over_allocated', Category::DataQuality, Severity::Blocker,
                ['count' => count($overAllocated)], $overAllocated,
                $this->step('inbound_reduce_allocation', [], $canAllocate));
        }

        if ($toAllocate !== []) {
            $findings[] = $this->finding('inbound.not_allocated', Category::Receipt, Severity::Notice,
                ['count' => count($toAllocate)], $toAllocate,
                $this->step('inbound_use_button', ['button' => __('admin.logistics.inbound.allocation.allocate')], $canAllocate));
        }

        if ($notOnReceipt !== []) {
            $findings[] = $this->finding('inbound.receipt_not_created', Category::Receipt, Severity::Notice,
                ['count' => count($notOnReceipt)], $notOnReceipt,
                $this->step('inbound_use_button', ['button' => __('admin.logistics.inbound.allocation.create_open_receipt')],
                    $this->can(InventoryPermission::ReceiptCreate)));
        }

        if ($overReceived !== []) {
            $findings[] = $this->finding('inbound.over_received', Category::Receipt, Severity::Warning,
                ['count' => count($overReceived)], $overReceived, $this->guidance('inbound_review_over_receipt'));
        }

        return $findings;
    }

    /**
     * Open receipts are read from the allocations the projection already loaded, so this costs no query.
     *
     * @return list<InventoryDiagnosticFinding>
     */
    private function receiptFindings(PurchaseInbound $inbound, LogisticsInboundData $projection): array
    {
        $receipts = [];
        $skus = [];

        foreach ($projection->lines as $line) {
            $skus[$line->purchaseInboundLineId] = [$line->sku, $line->uom];
        }

        foreach ($inbound->lines as $inboundLine) {
            assert($inboundLine instanceof PurchaseInboundLine);

            foreach ($inboundLine->allocations as $allocation) {
                assert($allocation instanceof PurchaseInboundAllocation);

                foreach ($allocation->inventoryOperationLines as $operationLine) {
                    assert($operationLine instanceof InventoryOperationLine);
                    $operation = $operationLine->operation;

                    if (! $operation instanceof InventoryOperation
                        || $operation->operation_type !== OperationType::Receipt
                        || ! in_array($operation->stage, self::OpenReceiptStages, true)) {
                        continue;
                    }

                    $receipts[$operation->getKey()] ??= ['operation' => $operation, 'warehouse' => $allocation->warehouse->name, 'lines' => []];
                    [$sku, $uom] = $skus[$inboundLine->getKey()] ?? ['—', null];
                    $receipts[$operation->getKey()]['lines'][] = $sku.' '.$this->quantity($operationLine->base_quantity ?? $operationLine->quantity, $uom);
                }
            }
        }

        if ($receipts === []) {
            return [];
        }

        $first = array_values($receipts)[0]['operation'];

        return [$this->finding('inbound.receipt_open', Category::Receipt, Severity::Notice,
            ['count' => count($receipts)],
            facts: array_values(array_map(fn (array $receipt): string => $this->fact('inbound_open_receipt', [
                'receipt' => $this->operationLabel($receipt['operation']),
                'stage' => $receipt['operation']->stageLabel(),
                'warehouse' => $receipt['warehouse'],
                'lines' => implode(', ', $receipt['lines']),
            ]), $receipts)),
            action: $this->viewLink(InventoryOperationResource::class, $first, 'inbound_open_receipt', [
                'number' => $this->operationLabel($first),
            ]),
        )];
    }

    private function allocationFact(LogisticsInboundLineData $line, LogisticsInboundAllocationData $allocation): string
    {
        return $this->fact('inbound_allocation_not_on_receipt', [
            'warehouse' => $allocation->warehouse,
            'sku' => $line->sku,
            'quantity' => $this->quantity($allocation->availableToReceive, $line->uom),
        ]);
    }

    private function hasBlocker(LogisticsInboundLineData $line, string $code): bool
    {
        return array_any($line->blockers, static fn (LogisticsInboundBlockerData $blocker): bool => $blocker->code === $code);
    }

    private function purchaseOrderLink(PurchaseInbound $inbound): InventoryDiagnosticAction
    {
        return $this->viewLink(PurchaseOrderResource::class, $inbound->purchaseOrder, 'inbound_open_purchase_order', [
            'number' => $inbound->purchaseOrder->purchase_order_number,
        ]);
    }

    /**
     * A step performed with a header button of this page, withheld when the user lacks the ability.
     *
     * @param  array<string, string>  $replace
     */
    private function step(string $labelKey, array $replace, bool $allowed): InventoryDiagnosticAction
    {
        $label = $this->actionLabel($labelKey, $replace);

        return $allowed
            ? InventoryDiagnosticAction::guidance($label)
            : new InventoryDiagnosticAction($label, null, false, __('inventory_view_ux.action_denied'));
    }

    private function can(InventoryPermission $permission): bool
    {
        return auth()->user()?->can($permission->value) ?? false;
    }

    private function operationLabel(InventoryOperation $operation): string
    {
        return (string) ($operation->operation_number ?: '#'.$operation->getKey());
    }
}
