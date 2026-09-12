<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\MovementType;
use App\Enums\OperationType;
use App\Models\ChartAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventorySetting;
use App\Models\InventoryValuationBalance;
use App\Models\InventoryValuationEntry;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ProductVariant;
use App\Models\PurchaseSetting;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WP-4.6 weighted-average valuation.
 *
 * Inventory movements remain the immutable quantity ledger. This service adds a
 * separate immutable valuation entry per canonical movement and a materialized
 * weighted-average balance for fast operational reads. Financial postings are
 * generated only from completed canonical inventory operations.
 */
final readonly class InventoryValuationService
{
    private const int QUANTITY_SCALE = 6;
    private const int COST_SCALE = 6;
    private const int MONEY_SCALE = 2;

    public function __construct(private JournalPostingService $journalPosting) {}

    public function processOperation(InventoryOperation $operation, ?User $actor = null): void
    {
        DB::transaction(function () use ($operation, $actor): void {
            /** @var InventoryOperation $locked */
            $locked = InventoryOperation::query()
                ->with(['lines.productVariant'])
                ->lockForUpdate()
                ->findOrFail($operation->getKey());

            $movements = InventoryMovement::query()
                ->where('source_type', 'inventory_operation')
                ->where('source_id', $locked->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($movements->isEmpty()) {
                return;
            }

            $postingActor = $actor ?? $this->operationActor($locked);

            match ($locked->operation_type) {
                OperationType::Receipt => $this->processReceipt($locked, $movements, $postingActor),
                OperationType::Delivery => $this->processDelivery($locked, $movements, $postingActor),
                OperationType::InternalTransfer => $this->processTransfer($locked, $movements),
            };
        });
    }

    /**
     * Applies valuation for non-operation shrinkage movements (damage,
     * disposal, and stock-count adjustments). Called from the movement observer
     * only after a canonical movement exists; zero-value/unvalued stock is a
     * no-op rather than an invented cost.
     */
    public function processStandaloneMovement(InventoryMovement $movement): void
    {
        if (! in_array($movement->movement_type, [MovementType::Damage, MovementType::Disposal, MovementType::Adjustment], true)) {
            return;
        }

        if (InventoryValuationEntry::query()->where('inventory_movement_id', $movement->getKey())->exists()) {
            return;
        }

        DB::transaction(function () use ($movement): void {
            $quantityDelta = $this->movementQuantity($movement);
            if (bccomp($quantityDelta, '0', self::QUANTITY_SCALE) === 0) {
                return;
            }

            $balance = $this->balanceForUpdate($movement->product_variant_id, $movement->warehouse_id);
            $unitCost = (string) $balance->average_unit_cost;
            if (bccomp($unitCost, '0', self::COST_SCALE) <= 0) {
                return;
            }

            $valueDelta = bcmul($quantityDelta, $unitCost, self::MONEY_SCALE);
            $this->applyBalanceDelta($balance, $quantityDelta, $valueDelta, $unitCost);
            $this->recordEntry($movement, $quantityDelta, $unitCost, $valueDelta, CarbonImmutable::parse($movement->created_at));

            if (bccomp($valueDelta, '0', self::MONEY_SCALE) < 0) {
                $actor = $movement->created_by !== null ? User::query()->find($movement->created_by) : null;
                $this->postShrinkageIfConfigured($movement, $actor, ltrim($valueDelta, '-'));
            }
        });
    }

    /**
     * @return array{valuation_minor:int, control_account_minor:int, difference_minor:int, is_reconciled:bool}
     */
    public function reconciliation(CarbonImmutable $asOf): array
    {
        $valuationMinor = (int) InventoryValuationEntry::query()
            ->whereDate('valuation_date', '<=', $asOf->toDateString())
            ->selectRaw('ROUND(COALESCE(SUM(inventory_value_delta), 0) * 100) as total_minor')
            ->value('total_minor');

        $settings = InventorySetting::current()->load('inventoryAssetAccount');
        $account = $settings->inventoryAssetAccount;

        if (! $account instanceof ChartAccount) {
            throw new DomainException('Inventory asset account must be configured before the inventory valuation can be reconciled.');
        }

        $totals = JournalEntryLine::query()
            ->where('chart_account_id', $account->getKey())
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('status', 'posted')
                ->whereDate('entry_date', '<=', $asOf->toDateString()))
            ->selectRaw('ROUND(COALESCE(SUM(debit - credit), 0) * 100) as total_minor')
            ->value('total_minor');

        $controlMinor = is_numeric($totals) ? (int) $totals : 0;
        $difference = $valuationMinor - $controlMinor;

        return [
            'valuation_minor' => $valuationMinor,
            'control_account_minor' => $controlMinor,
            'difference_minor' => $difference,
            'is_reconciled' => $difference === 0,
        ];
    }

    /** @param Collection<int, InventoryMovement> $movements */
    private function processReceipt(InventoryOperation $operation, Collection $movements, ?User $actor): void
    {
        $value = '0.00';

        foreach ($movements as $movement) {
            if ($movement->movement_type !== MovementType::Receipt || $this->entryExists($movement)) {
                continue;
            }

            $quantity = $this->positive($this->movementQuantity($movement));
            $line = $this->operationLine($operation, $movement);
            $unitCost = $this->receiptBaseUnitCost($line, $movement->product_variant_id);
            $lineValue = bcmul($quantity, $unitCost, self::MONEY_SCALE);
            $balance = $this->balanceForUpdate($movement->product_variant_id, $movement->warehouse_id);

            $this->applyBalanceDelta($balance, $quantity, $lineValue, $unitCost);
            $this->recordEntry($movement, $quantity, $unitCost, $lineValue, $this->operationDate($operation));
            $value = bcadd($value, $lineValue, self::MONEY_SCALE);
        }

        if (bccomp($value, '0', self::MONEY_SCALE) > 0) {
            $this->postReceiptIfConfigured($operation, $actor, $value);
        }
    }

    /** @param Collection<int, InventoryMovement> $movements */
    private function processDelivery(InventoryOperation $operation, Collection $movements, ?User $actor): void
    {
        $cogs = '0.00';

        foreach ($movements as $movement) {
            if ($movement->movement_type !== MovementType::Sale || $this->entryExists($movement)) {
                continue;
            }

            $quantity = $this->positive($this->movementQuantity($movement));
            $balance = $this->balanceForUpdate($movement->product_variant_id, $movement->warehouse_id);
            $unitCost = (string) $balance->average_unit_cost;
            $lineValue = bcmul($quantity, $unitCost, self::MONEY_SCALE);
            $negativeQuantity = '-'.$quantity;
            $negativeValue = '-'.$lineValue;

            $this->applyBalanceDelta($balance, $negativeQuantity, $negativeValue, $unitCost);
            $this->recordEntry($movement, $negativeQuantity, $unitCost, $negativeValue, $this->operationDate($operation));
            $cogs = bcadd($cogs, $lineValue, self::MONEY_SCALE);
        }

        if (bccomp($cogs, '0', self::MONEY_SCALE) > 0) {
            $this->postCogsIfConfigured($operation, $actor, $cogs);
        }
    }

    /** @param Collection<int, InventoryMovement> $movements */
    private function processTransfer(InventoryOperation $operation, Collection $movements): void
    {
        /** @var array<int, string> $costByVariant */
        $costByVariant = [];

        foreach ($movements as $movement) {
            if ($movement->movement_type !== MovementType::Transfer || $this->entryExists($movement)) {
                continue;
            }

            $quantityDelta = $this->movementQuantity($movement);
            $balance = $this->balanceForUpdate($movement->product_variant_id, $movement->warehouse_id);

            if (bccomp($quantityDelta, '0', self::QUANTITY_SCALE) < 0) {
                $unitCost = (string) $balance->average_unit_cost;
                $costByVariant[$movement->product_variant_id] = $unitCost;
            } else {
                $unitCost = $costByVariant[$movement->product_variant_id]
                    ?? $this->fallbackCost($movement->product_variant_id);
            }

            $valueDelta = bcmul($quantityDelta, $unitCost, self::MONEY_SCALE);
            $this->applyBalanceDelta($balance, $quantityDelta, $valueDelta, $unitCost);
            $this->recordEntry($movement, $quantityDelta, $unitCost, $valueDelta, $this->operationDate($operation));
        }
    }

    private function postReceiptIfConfigured(InventoryOperation $operation, ?User $actor, string $amount): void
    {
        $inventory = InventorySetting::current()->load('inventoryAssetAccount')->inventoryAssetAccount;
        $grni = PurchaseSetting::current()->load('grniAccount')->grniAccount;

        if (! $inventory instanceof ChartAccount || ! $grni instanceof ChartAccount) {
            return;
        }

        $this->assertPostable($inventory, 'inventory asset');
        $this->assertPostable($grni, 'GRNI');
        $postingActor = $this->requireActor($actor, 'receipt valuation');

        if ($this->journalAlreadyExists($operation, 'Inventory receipt valuation')) {
            return;
        }

        $this->journalPosting->postNew(
            $postingActor,
            $this->operationDate($operation),
            [
                ['chart_account_id' => $inventory->getKey(), 'debit' => $amount, 'credit' => '0.00', 'description' => 'Inventory received'],
                ['chart_account_id' => $grni->getKey(), 'debit' => '0.00', 'credit' => $amount, 'description' => 'Goods received not invoiced'],
            ],
            'Inventory receipt valuation '.$operation->operation_number,
            $operation,
        );
    }

    private function postCogsIfConfigured(InventoryOperation $operation, ?User $actor, string $amount): void
    {
        $settings = InventorySetting::current()->load(['inventoryAssetAccount', 'cogsAccount']);
        $inventory = $settings->inventoryAssetAccount;
        $cogs = $settings->cogsAccount;

        if (! $inventory instanceof ChartAccount || ! $cogs instanceof ChartAccount) {
            return;
        }

        $this->assertPostable($inventory, 'inventory asset');
        $this->assertPostable($cogs, 'COGS');
        $postingActor = $this->requireActor($actor, 'cost of sales');

        if ($this->journalAlreadyExists($operation, 'Inventory COGS')) {
            return;
        }

        $this->journalPosting->postNew(
            $postingActor,
            $this->operationDate($operation),
            [
                ['chart_account_id' => $cogs->getKey(), 'debit' => $amount, 'credit' => '0.00', 'description' => 'Cost of goods sold'],
                ['chart_account_id' => $inventory->getKey(), 'debit' => '0.00', 'credit' => $amount, 'description' => 'Inventory issued'],
            ],
            'Inventory COGS '.$operation->operation_number,
            $operation,
        );
    }

    private function postShrinkageIfConfigured(InventoryMovement $movement, ?User $actor, string $amount): void
    {
        $settings = InventorySetting::current()->load(['inventoryAssetAccount', 'shrinkageExpenseAccount']);
        $inventory = $settings->inventoryAssetAccount;
        $shrinkage = $settings->shrinkageExpenseAccount;

        if (! $inventory instanceof ChartAccount || ! $shrinkage instanceof ChartAccount || ! $actor instanceof User) {
            return;
        }

        $this->assertPostable($inventory, 'inventory asset');
        $this->assertPostable($shrinkage, 'shrinkage expense');

        if (JournalEntry::query()
            ->where('source_type', $movement->getMorphClass())
            ->where('source_id', $movement->getKey())
            ->where('description', 'Inventory shrinkage '.$movement->getKey())
            ->exists()) {
            return;
        }

        $this->journalPosting->postNew(
            $actor,
            CarbonImmutable::parse($movement->created_at),
            [
                ['chart_account_id' => $shrinkage->getKey(), 'debit' => $amount, 'credit' => '0.00', 'description' => 'Inventory shrinkage'],
                ['chart_account_id' => $inventory->getKey(), 'debit' => '0.00', 'credit' => $amount, 'description' => 'Inventory write-down'],
            ],
            'Inventory shrinkage '.$movement->getKey(),
            $movement,
        );
    }

    private function balanceForUpdate(int $variantId, int $warehouseId): InventoryValuationBalance
    {
        $query = InventoryValuationBalance::query()
            ->where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId);

        $balance = $query->lockForUpdate()->first();
        if ($balance instanceof InventoryValuationBalance) {
            return $balance;
        }

        InventoryValuationBalance::query()->create([
            'product_variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity_base' => '0.000000',
            'average_unit_cost' => '0.000000',
            'inventory_value' => '0.00',
        ]);

        return $query->lockForUpdate()->firstOrFail();
    }

    private function applyBalanceDelta(
        InventoryValuationBalance $balance,
        string $quantityDelta,
        string $valueDelta,
        string $incomingUnitCost,
    ): void {
        $quantity = bcadd((string) $balance->quantity_base, $quantityDelta, self::QUANTITY_SCALE);
        $value = bcadd((string) $balance->inventory_value, $valueDelta, self::MONEY_SCALE);

        if (bccomp($quantity, '0', self::QUANTITY_SCALE) < 0) {
            throw new DomainException('Inventory valuation cannot become negative.');
        }

        if (bccomp($quantity, '0', self::QUANTITY_SCALE) === 0) {
            $value = '0.00';
            $average = '0.000000';
        } elseif (bccomp($quantityDelta, '0', self::QUANTITY_SCALE) > 0) {
            $average = bcdiv($value, $quantity, self::COST_SCALE);
        } else {
            $average = (string) $balance->average_unit_cost;
        }

        $balance->forceFill([
            'quantity_base' => $quantity,
            'inventory_value' => $value,
            'average_unit_cost' => $average !== '' ? $average : $incomingUnitCost,
        ])->save();
    }

    private function recordEntry(
        InventoryMovement $movement,
        string $quantityDelta,
        string $unitCost,
        string $valueDelta,
        CarbonImmutable $date,
    ): void {
        InventoryValuationEntry::query()->firstOrCreate(
            ['inventory_movement_id' => $movement->getKey()],
            [
                'product_variant_id' => $movement->product_variant_id,
                'warehouse_id' => $movement->warehouse_id,
                'valuation_date' => $date->toDateString(),
                'valuation_method' => 'weighted_average',
                'base_quantity_delta' => $quantityDelta,
                'unit_cost_snapshot' => $unitCost,
                'inventory_value_delta' => $valueDelta,
            ],
        );
    }

    private function operationLine(InventoryOperation $operation, InventoryMovement $movement): ?InventoryOperationLine
    {
        if ($movement->source_line_id === null) {
            return null;
        }

        return $operation->lines->firstWhere('id', $movement->source_line_id);
    }

    private function receiptBaseUnitCost(?InventoryOperationLine $line, int $variantId): string
    {
        if ($line instanceof InventoryOperationLine && $line->unit_cost !== null) {
            $factor = $line->conversion_factor_snapshot ?? '1.000000';
            if (bccomp((string) $factor, '0', self::QUANTITY_SCALE) > 0) {
                return bcdiv((string) $line->unit_cost, (string) $factor, self::COST_SCALE);
            }
        }

        return $this->fallbackCost($variantId);
    }

    private function fallbackCost(int $variantId): string
    {
        $cost = ProductVariant::query()->whereKey($variantId)->value('cost_price');

        return is_numeric($cost) ? number_format((float) $cost, self::COST_SCALE, '.', '') : '0.000000';
    }

    private function movementQuantity(InventoryMovement $movement): string
    {
        $raw = $movement->base_quantity_delta ?? $movement->quantity;
        $quantity = number_format((float) $raw, self::QUANTITY_SCALE, '.', '');

        return $quantity;
    }

    private function positive(string $quantity): string
    {
        return str_starts_with($quantity, '-') ? substr($quantity, 1) : $quantity;
    }

    private function operationDate(InventoryOperation $operation): CarbonImmutable
    {
        return CarbonImmutable::parse($operation->completed_at ?? now());
    }

    private function operationActor(InventoryOperation $operation): ?User
    {
        $actorId = $operation->updated_by ?? $operation->created_by;

        return is_numeric($actorId) ? User::query()->find((int) $actorId) : null;
    }

    private function requireActor(?User $actor, string $context): User
    {
        if (! $actor instanceof User) {
            throw new DomainException("An actor is required for {$context} accounting posting.");
        }

        return $actor;
    }

    private function assertPostable(ChartAccount $account, string $label): void
    {
        if (! $account->is_active || ! $account->is_postable) {
            throw new DomainException("The configured {$label} account must be active and postable.");
        }
    }

    private function entryExists(InventoryMovement $movement): bool
    {
        return InventoryValuationEntry::query()->where('inventory_movement_id', $movement->getKey())->exists();
    }

    private function journalAlreadyExists(InventoryOperation $operation, string $prefix): bool
    {
        return JournalEntry::query()
            ->where('source_type', $operation->getMorphClass())
            ->where('source_id', $operation->getKey())
            ->where('description', 'like', $prefix.'%')
            ->exists();
    }
}
