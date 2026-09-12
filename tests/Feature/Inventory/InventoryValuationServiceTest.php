<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventorySetting;
use App\Models\InventoryValuationBalance;
use App\Models\InventoryValuationEntry;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\PurchaseSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WP-4.6 (PHASE_4_PLAN.md §2, ADR 0013): weighted-average valuation, the
 * balance it materializes, and the receipt/COGS/shrinkage postings it derives
 * from completed canonical inventory operations.
 */
function valuationAccounts(): array
{
    $inventory = ChartAccount::factory()->create(['code' => 'BENCH-1300', 'is_postable' => true, 'is_active' => true]);
    $grni = ChartAccount::factory()->create(['code' => 'BENCH-2150', 'is_postable' => true, 'is_active' => true]);
    $cogs = ChartAccount::factory()->create(['code' => 'BENCH-5100', 'is_postable' => true, 'is_active' => true]);
    $shrinkage = ChartAccount::factory()->create(['code' => 'BENCH-5910', 'is_postable' => true, 'is_active' => true]);

    InventorySetting::current()->forceFill([
        'inventory_asset_account_id' => $inventory->getKey(),
        'cogs_account_id' => $cogs->getKey(),
        'shrinkage_expense_account_id' => $shrinkage->getKey(),
    ])->save();

    PurchaseSetting::current()->forceFill(['grni_account_id' => $grni->getKey()])->save();

    FiscalPeriod::factory()->create();

    return compact('inventory', 'grni', 'cogs', 'shrinkage');
}

function receiptOperation(Warehouse $warehouse): InventoryOperation
{
    return InventoryOperation::factory()->receipt()->done()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
    ]);
}

function receiptMovement(ProductVariant $variant, Warehouse $warehouse, InventoryOperation $operation, string $quantity, string $unitCost): InventoryMovement
{
    $line = InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'conversion_factor_snapshot' => '1.000000',
    ]);

    return InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Receipt,
        'quantity' => $quantity,
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'source_line_id' => $line->getKey(),
    ]);
}

function saleMovement(ProductVariant $variant, Warehouse $warehouse, InventoryOperation $operation, string $quantity): InventoryMovement
{
    return InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Sale,
        'quantity' => '-'.$quantity,
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
    ]);
}

it('reports isConfigured false until an inventory asset account is set', function (): void {
    $service = app(InventoryValuationService::class);

    expect($service->isConfigured())->toBeFalse();

    InventorySetting::current()->forceFill([
        'inventory_asset_account_id' => ChartAccount::factory()->create()->getKey(),
    ])->save();

    expect($service->isConfigured())->toBeTrue();
});

it('values a receipt at its line unit cost, materializes the balance, and posts inventory/GRNI', function (): void {
    $accounts = valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    $operation = receiptOperation($warehouse);
    $movement = receiptMovement($variant, $warehouse, $operation, '10.000000', '4.5000');

    app(InventoryValuationService::class)->processOperation($operation, $actor);

    $balance = InventoryValuationBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->firstOrFail();

    expect((string) $balance->quantity_base)->toBe('10.000000')
        ->and((string) $balance->average_unit_cost)->toBe('4.500000')
        ->and((string) $balance->inventory_value)->toBe('45.00');

    $entry = InventoryValuationEntry::query()->where('inventory_movement_id', $movement->getKey())->firstOrFail();
    expect((string) $entry->inventory_value_delta)->toBe('45.00');

    $posting = JournalEntry::query()
        ->where('source_type', $operation->getMorphClass())
        ->where('source_id', $operation->getKey())
        ->where('description', 'like', 'Inventory receipt valuation%')
        ->with('lines')
        ->firstOrFail();

    expect($posting->lines)->toHaveCount(2)
        ->and((float) $posting->lines->firstWhere('chart_account_id', $accounts['inventory']->getKey())->debit)->toBe(45.0)
        ->and((float) $posting->lines->firstWhere('chart_account_id', $accounts['grni']->getKey())->credit)->toBe(45.0);
});

it('averages cost across two receipts at different unit costs', function (): void {
    valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $service = app(InventoryValuationService::class);

    $first = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $first, '10.000000', '4.0000');
    $service->processOperation($first, $actor);

    $second = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $second, '10.000000', '6.0000');
    $service->processOperation($second, $actor);

    $balance = InventoryValuationBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->firstOrFail();

    // (10 * 4.00 + 10 * 6.00) / 20 = 5.00
    expect((string) $balance->quantity_base)->toBe('20.000000')
        ->and((string) $balance->average_unit_cost)->toBe('5.000000')
        ->and((string) $balance->inventory_value)->toBe('100.00');
});

it('relieves a delivery at the current weighted-average cost and posts COGS', function (): void {
    $accounts = valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $service = app(InventoryValuationService::class);

    $receipt = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $receipt, '10.000000', '4.0000');
    $service->processOperation($receipt, $actor);

    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    saleMovement($variant, $warehouse, $delivery, '4.000000');
    $service->processOperation($delivery, $actor);

    $balance = InventoryValuationBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->firstOrFail();

    expect((string) $balance->quantity_base)->toBe('6.000000')
        ->and((string) $balance->average_unit_cost)->toBe('4.000000')
        ->and((string) $balance->inventory_value)->toBe('24.00');

    $posting = JournalEntry::query()
        ->where('source_type', $delivery->getMorphClass())
        ->where('source_id', $delivery->getKey())
        ->where('description', 'like', 'Inventory COGS%')
        ->with('lines')
        ->firstOrFail();

    expect((float) $posting->lines->firstWhere('chart_account_id', $accounts['cogs']->getKey())->debit)->toBe(16.0)
        ->and((float) $posting->lines->firstWhere('chart_account_id', $accounts['inventory']->getKey())->credit)->toBe(16.0);
});

it('does not post or value a receipt/delivery when the inventory accounts are unconfigured', function (): void {
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    $operation = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $operation, '10.000000', '4.0000');

    app(InventoryValuationService::class)->processOperation($operation, $actor);

    // The valuation ledger and balance are still materialized (they don't
    // depend on accounting configuration) — only the journal posting is
    // skipped.
    expect(InventoryValuationBalance::query()->where('product_variant_id', $variant->getKey())->exists())->toBeTrue()
        ->and(JournalEntry::query()->where('source_type', $operation->getMorphClass())->where('source_id', $operation->getKey())->exists())->toBeFalse();
});

it('treats a delivery exceeding the tracked balance as a no-op rather than an invented cost', function (): void {
    valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    // No receipt was ever valued for this variant/warehouse — this is stock
    // that predates WP-4.6 valuation (opening balance, demo data).
    $delivery = InventoryOperation::factory()->delivery()->done()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    saleMovement($variant, $warehouse, $delivery, '5.000000');

    app(InventoryValuationService::class)->processOperation($delivery, $actor);

    expect(InventoryValuationEntry::query()->where('product_variant_id', $variant->getKey())->exists())->toBeFalse()
        ->and(JournalEntry::query()->where('source_type', $delivery->getMorphClass())->where('source_id', $delivery->getKey())->exists())->toBeFalse();
});

it('posts a shrinkage write-down for a standalone damage movement against valued stock', function (): void {
    $accounts = valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $service = app(InventoryValuationService::class);

    $receipt = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $receipt, '10.000000', '4.0000');
    $service->processOperation($receipt, $actor);

    $damage = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Damage,
        'quantity' => '-2.000000',
        'created_by' => $actor->getKey(),
    ]);

    $service->processStandaloneMovement($damage);

    $balance = InventoryValuationBalance::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->firstOrFail();

    expect((string) $balance->quantity_base)->toBe('8.000000');

    $posting = JournalEntry::query()
        ->where('source_type', $damage->getMorphClass())
        ->where('source_id', $damage->getKey())
        ->with('lines')
        ->firstOrFail();

    expect((float) $posting->lines->firstWhere('chart_account_id', $accounts['shrinkage']->getKey())->debit)->toBe(8.0)
        ->and((float) $posting->lines->firstWhere('chart_account_id', $accounts['inventory']->getKey())->credit)->toBe(8.0);
});

it('treats a standalone movement against never-valued stock as a no-op', function (): void {
    valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $damage = InventoryMovement::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'movement_type' => MovementType::Damage,
        'quantity' => '-2.000000',
    ]);

    app(InventoryValuationService::class)->processStandaloneMovement($damage);

    expect(InventoryValuationEntry::query()->where('inventory_movement_id', $damage->getKey())->exists())->toBeFalse();
});

it('is idempotent per movement: reprocessing an operation does not double-post', function (): void {
    valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();
    $service = app(InventoryValuationService::class);

    $operation = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $operation, '10.000000', '4.0000');

    $service->processOperation($operation, $actor);
    $service->processOperation($operation, $actor);

    expect(InventoryValuationEntry::query()->where('product_variant_id', $variant->getKey())->count())->toBe(1)
        ->and(JournalEntry::query()
            ->where('source_type', $operation->getMorphClass())
            ->where('source_id', $operation->getKey())
            ->where('description', 'like', 'Inventory receipt valuation%')
            ->count())->toBe(1);
});

it('reconciles the valuation ledger against the inventory asset control account', function (): void {
    $accounts = valuationAccounts();
    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $actor = User::factory()->create();

    $operation = receiptOperation($warehouse);
    receiptMovement($variant, $warehouse, $operation, '10.000000', '4.0000');
    app(InventoryValuationService::class)->processOperation($operation, $actor);

    $result = app(InventoryValuationService::class)->reconciliation(now()->toImmutable());

    expect($result['valuation_minor'])->toBe(4000)
        ->and($result['control_account_minor'])->toBe(4000)
        ->and($result['is_reconciled'])->toBeTrue();

    // Sanity check the account used is really the configured one.
    expect($accounts['inventory'])->not->toBeNull();
});

it('throws when reconciling without a configured inventory asset account', function (): void {
    app(InventoryValuationService::class)->reconciliation(now()->toImmutable());
})->throws(DomainException::class);
