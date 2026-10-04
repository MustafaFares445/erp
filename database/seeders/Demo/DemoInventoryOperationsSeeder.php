<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Data\Inventory\CountScopeData;
use App\Data\Inventory\DamageDraftData;
use App\Data\Inventory\DisposalDraftData;
use App\Data\Inventory\RecoveryDraftData;
use App\Data\Inventory\TransferReceiptCommand;
use App\Data\Inventory\TransferReceiptLine;
use App\Enums\ConditionChangeReason;
use App\Enums\CountScope;
use App\Enums\OperationType;
use App\Enums\StockCondition;
use App\Enums\TransferDiscrepancyDisposition;
use App\Models\InventoryAdjustment;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryConditionChangeService;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryCountService;
use App\Services\Inventory\InventoryLotService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * Warehouse-side activity between 12 September and 2 October: stock transfers in every
 * reachable stage, adjustments, a physical count, a receipt correction and the damage /
 * recovery / disposal workflow. Quantities are clamped to what is actually on hand at run
 * time, so the story stays valid whatever the sales and purchasing seeders consumed.
 */
final class DemoInventoryOperationsSeeder extends DemoSeeder
{
    private DemoInventory $inventory;

    private InventoryOperationService $operations;

    protected function seed(DemoContext $context): void
    {
        $this->inventory = DemoInventory::make();
        $this->operations = app(InventoryOperationService::class);

        if (InventoryOperation::query()->where('notes', 'like', '[DEMO] Transfer %')->exists()) {
            $this->note('Inventory operations already present - skipped.');

            return;
        }

        $this->transfers($context);
        $this->adjustments($context);
        $this->physicalCount($context);
        $this->receiptCorrection($context);
        $this->conditionChanges($context);

        $context->at('2026-10-03 07:30');
        Artisan::call('inventory:alerts:reconcile');
    }

    private function transfers(DemoContext $context): void
    {
        // T1: completed, single product, Main -> Repair.
        $context->at('2026-09-12 10:00');
        $actor = $context->as('operations');
        $this->transfer($actor, 'WH-MAIN', 'WH-REPAIR', [['P020', 'PRO', 2]], 'T1 maintenance stock to the bench', complete: true);

        // T4: completed, several products, Main -> Repair.
        $context->at('2026-09-16 11:00');
        $context->as('operations');
        $this->transfer($actor, 'WH-MAIN', 'WH-REPAIR', [['P016', 'BASIC', 3], ['P020', 'BASIC', 4], ['P012', '21MM', 2]], 'T4 bench restock, three products', complete: true);

        // T5: discrepancy - shortage recorded on receipt.
        $context->at('2026-09-22 09:30');
        $context->as('operations');

        $shortage = $this->transfer($actor, 'WH-MAIN', 'WH-REPAIR', [['P016', 'PREMIUM', 4]], 'T5 premium burs, short receipt', dispatchOnly: true);
        $context->at('2026-09-23 14:00');
        $line = $shortage?->lines()->first();
        if ($shortage instanceof InventoryOperation && $line !== null) {
            $this->operations->receiveTransfer($shortage->refresh(), $actor, new TransferReceiptCommand([
                new TransferReceiptLine(DemoContext::keyOf($line), $this->receivable($line, 1), TransferDiscrepancyDisposition::Shortage, 'One set missing from the sealed carton on arrival.'),
            ]));
        }

        // T6: partially received (stays open), Main -> Cold.
        $context->at('2026-09-25 10:00');
        $context->as('operations');

        $partial = $this->transfer($actor, 'WH-MAIN', 'WH-COLD', [['P005', '35MM', 10]], 'T6 abutments to cold storage, first pallet', dispatchOnly: true);
        $context->at('2026-09-26 15:00');
        $line = $partial?->lines()->first();
        if ($partial instanceof InventoryOperation && $line !== null) {
            $this->operations->receiveTransfer($partial->refresh(), $actor, new TransferReceiptCommand([
                new TransferReceiptLine(DemoContext::keyOf($line), $this->receivable($line, 6)),
            ]));
        }

        // T2: dispatched and still in transit.
        $context->at('2026-09-29 13:00');
        $context->as('operations');
        $this->transfer($actor, 'WH-MAIN', 'WH-COLD', [['P011', '5ML', 4]], 'T2 bonding agent to cold storage', dispatchOnly: true);

        // T7: ready (stock reserved at source).
        $context->at('2026-10-01 09:00');
        $context->as('operations');
        $this->transfer($actor, 'WH-MAIN', 'WH-REPAIR', [['P009', 'STD', 3]], 'T7 cement kits for the bench, ready to ship');

        // T3: draft, Cold -> Main.
        $context->at('2026-10-01 11:30');
        $context->as('operations');
        $this->transfer($actor, 'WH-COLD', 'WH-MAIN', [['P014', '05G', 2]], 'T3 bone graft back to main store', draft: true);

        // T8: waiting - more requested than the cold store holds.
        $context->at('2026-10-02 10:00');
        $context->as('operations');
        $this->transfer($actor, 'WH-COLD', 'WH-REPAIR', [['P014', '10G', 40]], 'T8 oversized request waiting for stock', unclamped: true);

        // T9: cancelled before dispatch.
        $context->at('2026-10-02 14:00');
        $context->as('operations');

        $cancelled = $this->transfer($actor, 'WH-MAIN', 'WH-COLD', [['P018', 'UPPER', 5]], 'T9 duplicate request', draft: true);
        if ($cancelled instanceof InventoryOperation) {
            $this->operations->cancel($cancelled->refresh(), $actor, 'Duplicate of an existing request.');
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int}>  $items
     */
    private function transfer(
        User $actor,
        string $from,
        string $to,
        array $items,
        string $label,
        bool $complete = false,
        bool $dispatchOnly = false,
        bool $draft = false,
        bool $unclamped = false,
    ): ?InventoryOperation {
        $source = $this->inventory->warehouse($from);
        $operation = InventoryOperation::query()->create([
            'operation_type' => OperationType::InternalTransfer,
            'source_warehouse_id' => $source->getKey(),
            'destination_warehouse_id' => $this->inventory->warehouse($to)->getKey(),
            'scheduled_at' => now(),
            'responsible_id' => $actor->getKey(),
            'notes' => "[DEMO] Transfer {$label}",
        ]);

        $lines = 0;
        foreach ($items as [$product, $suffix, $wanted]) {
            $variant = $this->inventory->variant($product, $suffix);
            $quantity = $unclamped ? $wanted : min($wanted, $this->available($variant, $source));

            if ($quantity <= 0) {
                continue;
            }

            $lines += $this->addTransferLine($operation, $variant, $source, $quantity);
        }

        if ($lines === 0) {
            $operation->forceDelete();

            return null;
        }

        if ($draft) {
            return $operation;
        }

        $this->operations->markReady($operation, $actor);

        if ($complete || $dispatchOnly) {
            $this->operations->dispatch($operation->refresh(), $actor);
        }

        if ($complete) {
            $this->operations->complete($operation->refresh(), $actor);
        }

        return $operation->refresh();
    }

    private function addTransferLine(InventoryOperation $operation, ProductVariant $variant, Warehouse $source, int $quantity): int
    {
        $attributes = ['product_variant_id' => $variant->getKey(), 'quantity' => $quantity, 'unit_id' => $variant->unit_id];

        if ($variant->track_serials) {
            $units = $variant->serializedUnits()->where('warehouse_id', $source->getKey())->limit($quantity)->get();
            foreach ($units as $unit) {
                $operation->lines()->create([...$attributes, 'quantity' => 1, 'serialized_inventory_unit_id' => $unit->getKey()]);
            }

            return $units->count();
        }

        if ($variant->track_batches) {
            $lot = app(InventoryLotService::class)->availableLots(DemoContext::keyOf($variant), DemoContext::keyOf($source))->first();
            if ($lot === null) {
                return 0;
            }
            $attributes['inventory_lot_id'] = $lot->getKey();
        }

        $operation->lines()->create($attributes);

        return 1;
    }

    private function receivable(InventoryOperationLine $line, int $wanted): string
    {
        return number_format(min($wanted, (float) $line->dispatched_base_quantity), 6, '.', '');
    }

    private function adjustments(DemoContext $context): void
    {
        $service = app(InventoryAdjustmentService::class);
        $main = $this->inventory->warehouse('WH-MAIN');

        $scenes = [
            ['2026-09-20 16:00', 'P018', 'LOWER', 3, 'Physical count surplus', ConditionChangeReason::Other, true],
            ['2026-09-24 12:00', 'P010', 'A2', -2, 'Damaged units found on the shelf', ConditionChangeReason::DamagedInTransit, true],
            ['2026-09-28 15:30', 'P013', '1L', -3, 'Stock count correction', ConditionChangeReason::Other, true],
            ['2026-10-02 16:30', 'P007', 'M', -2, 'Pending recount of glove stock', ConditionChangeReason::Other, false],
        ];

        foreach ($scenes as [$moment, $product, $suffix, $delta, $reason, $category, $confirm]) {
            $context->at($moment);
            $maker = $context->as('operations');
            $variant = $this->inventory->variant($product, $suffix);
            $lot = app(InventoryLotService::class)->availableLots(DemoContext::keyOf($variant), DemoContext::keyOf($main))->first();

            if ($lot === null) {
                continue;
            }

            $current = (float) $lot->conditionOnHandQuantity(StockCondition::Saleable, DemoContext::keyOf($main));
            $target = max(0, $current + $delta);

            $adjustment = InventoryAdjustment::query()->create([
                'warehouse_id' => $main->getKey(),
                'reason' => "[DEMO] {$reason}",
                'reason_category' => $category,
            ]);
            $adjustment->items()->create([
                'product_variant_id' => $variant->getKey(),
                'inventory_lot_id' => $lot->getKey(),
                'stock_condition' => StockCondition::Saleable,
                'new_quantity' => $target,
            ]);

            if ($confirm) {
                $context->at(Carbon::parse($moment)->addMinutes(30)->format('Y-m-d H:i'));
                $service->confirm($adjustment->refresh(), $context->actor('admin'));
            }
        }

        Auth::setUser($context->actor('operations'));
    }

    private function physicalCount(DemoContext $context): void
    {
        $service = app(InventoryCountService::class);
        $repair = $this->inventory->warehouse('WH-REPAIR');

        $context->at('2026-09-29 09:00');
        $counter = $context->as('operations');
        $count = $service->open(new CountScopeData(DemoContext::keyOf($repair), CountScope::Warehouse, null, null, null, [StockCondition::Saleable->value], null), $counter);

        $first = true;
        foreach ($count->lines as $line) {
            $quantity = (float) $line->system_base_quantity;
            if ($first && $quantity >= 1) {
                $quantity -= 1;
                $first = false;
            }
            $service->recordCount($line, number_format($quantity, 6, '.', ''), $counter);
        }

        $context->at('2026-09-29 11:00');
        $service->submitForReview($count->refresh(), $counter);
        $context->at('2026-09-29 15:00');
        $service->confirm($count->refresh(), $context->actor('admin'));
    }

    private function receiptCorrection(DemoContext $context): void
    {
        $receipt = InventoryOperation::query()->where('supplier_reference', 'DEMO-OPEN-DEMO-SUP-001-WH-MAIN')->first();
        $line = $receipt?->lines()->whereHas('productVariant', fn ($q) => $q->where('sku', DemoMasterDataSeeder::sku('P007', 'S')))->first();

        if (! $receipt instanceof InventoryOperation || $line === null) {
            return;
        }

        $context->at('2026-09-18 11:00');
        $actor = $context->as('operations');
        $service = app(InventoryCorrectionService::class);
        $correction = $service->createReceiptCorrection($actor, $receipt, 'Two glove cartons recorded on the delivery note never arrived.');
        $service->addReceiptLine($correction, $line, '2.000000');
        $service->post($correction->refresh(), $actor);
    }

    private function conditionChanges(DemoContext $context): void
    {
        $service = app(InventoryConditionChangeService::class);
        $main = $this->inventory->warehouse('WH-MAIN');
        $variant = $this->inventory->variant('P012', '21MM');
        $lot = app(InventoryLotService::class)->availableLots(DemoContext::keyOf($variant), DemoContext::keyOf($main))->first();

        if ($lot === null) {
            return;
        }

        $context->at('2026-09-26 10:00');
        $admin = $context->as('admin');
        $damage = $service->draftDamage(new DamageDraftData(DemoContext::keyOf($variant), DemoContext::keyOf($main), DemoContext::keyOf($lot), null, '3.000000', ConditionChangeReason::DamagedInTransit, 'Cartons crushed during an internal move.'), $admin);
        $service->post($damage->refresh(), $admin);

        $context->at('2026-09-27 09:30');
        $recovery = $service->draftRecovery(new RecoveryDraftData(DemoContext::keyOf($damage), '1.000000', ConditionChangeReason::QualityInspectionPassed, 'One set passed inspection and is repackaged.'), $admin);
        $service->post($recovery->refresh(), $admin);

        $context->at('2026-09-30 14:00');
        $maker = $context->as('operations');
        $disposal = $service->draftDisposal(new DisposalDraftData(DemoContext::keyOf($variant), DemoContext::keyOf($main), DemoContext::keyOf($lot), null, '2.000000', ConditionChangeReason::Other, 'Beyond repair after the crushed-carton incident.', DemoContext::keyOf($admin)), $maker);
        $disposal->addMediaFromString('DEMO-DISPOSAL-EVIDENCE-PLACEHOLDER')->usingFileName('disposal-evidence.jpg')->toMediaCollection('disposal-evidence');
        $context->at('2026-09-30 15:00');
        $service->post($disposal->refresh(), $admin);
    }

    private function available(ProductVariant $variant, Warehouse $warehouse): int
    {
        $stock = InventoryStock::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->first();

        return $stock instanceof InventoryStock ? (int) floor((float) $stock->available_quantity) : 0;
    }
}
