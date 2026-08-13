<?php

declare(strict_types=1);

use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

function stockTransferService(): StockTransferService
{
    return app(StockTransferService::class);
}

it('dispatches and receives a transfer, moving stock in two explicit workflow steps', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '4.000']);

    $actor = User::factory()->create();

    stockTransferService()->dispatch($transfer, $actor);

    $transfer->refresh();

    expect($transfer->status)->toBe(TransferStatus::Dispatched)
        ->and($transfer->transfer_number)->not->toBeNull();

    $sourceStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $from->id)->firstOrFail();

    expect((float) $sourceStock->on_hand_quantity)->toBe(6.0)
        ->and((float) $sourceStock->available_quantity)->toBe(6.0)
        ->and(InventoryStock::query()->where('warehouse_id', $to->id)->doesntExist())->toBeTrue();

    $dispatchMovements = InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->get();

    expect($dispatchMovements)->toHaveCount(1)
        ->and($dispatchMovements->firstWhere('warehouse_id', $from->id)?->quantity)->toEqual(-4.0);

    $auditLog = AuditLog::query()->where('subject_type', StockTransfer::class)->where('subject_id', $transfer->id)
        ->where('description', 'inventory.transfer.dispatched')->firstOrFail();

    expect($auditLog->causer_id)->toBe($actor->id)
        ->and($auditLog->causer->is($actor))->toBeTrue()
        ->and($auditLog->source_channel)->toBe('dashboard');

    stockTransferService()->receive($transfer, $actor);
    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $to->id)->firstOrFail();
    $movements = InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->get();

    expect($transfer->fresh()->status)->toBe(TransferStatus::Received)
        ->and((float) $destinationStock->on_hand_quantity)->toBe(4.0)
        ->and((float) $destinationStock->available_quantity)->toBe(4.0)
        ->and($movements)->toHaveCount(2)
        ->and($movements->firstWhere('warehouse_id', $to->id)?->quantity)->toEqual(4.0)
        ->and(AuditLog::query()->where('description', 'inventory.transfer.received')->where('subject_id', $transfer->id)->exists())->toBeTrue();
});

it('moves a package when its recorded goods are received', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $package = Package::factory()->for($from)->create();
    InventoryStock::factory()->for($variant)->for($from)->create([
        'on_hand_quantity' => '4.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '4.000',
    ]);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'package_id' => $package->getKey(),
        'quantity' => '2.000',
    ]);

    $actor = User::factory()->create();
    stockTransferService()->dispatch($transfer, $actor);
    stockTransferService()->receive($transfer->refresh(), $actor);

    expect($package->refresh()->warehouse_id)->toBe($to->getKey());
});

it('rejects a transfer package that belongs to another warehouse', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $foreignPackage = Package::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create([
        'on_hand_quantity' => '4.000',
        'reserved_quantity' => '0.000',
        'available_quantity' => '4.000',
    ]);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'package_id' => $foreignPackage->getKey(),
        'quantity' => '1.000',
    ]);

    expect(fn (): mixed => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.package.errors.warehouse_mismatch'));
});

it('sums duplicate lines for availability and writes one movement per line at each workflow step', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '3.000']);
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '4.000']);

    $actor = User::factory()->create();
    stockTransferService()->dispatch($transfer, $actor);

    $sourceStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $from->id)->firstOrFail();

    expect((float) $sourceStock->on_hand_quantity)->toBe(3.0)
        ->and(InventoryStock::query()->where('warehouse_id', $to->id)->doesntExist())->toBeTrue()
        ->and(InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->count())->toBe(2);

    stockTransferService()->receive($transfer, $actor);
    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $to->id)->firstOrFail();

    expect((float) $destinationStock->on_hand_quantity)->toBe(7.0)
        ->and(InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->count())->toBe(4);
});

it('establishes a destination balance for a variant with no existing stock row', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '7.000', 'reserved_quantity' => '0.000', 'available_quantity' => '7.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '7.000']);

    $actor = User::factory()->create();
    stockTransferService()->dispatch($transfer, $actor);
    stockTransferService()->receive($transfer, $actor);

    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $to->id)->firstOrFail();

    expect((float) $destinationStock->on_hand_quantity)->toBe(7.0)
        ->and((float) $destinationStock->reserved_quantity)->toBe(0.0);
});

it('refuses dispatch when the source lacks enough available stock, leaving nothing changed', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '2.000', 'reserved_quantity' => '0.000', 'available_quantity' => '2.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '5.000']);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $from->id)->first()?->on_hand_quantity)->toEqual(2.0)
        ->and(InventoryStock::query()->where('warehouse_id', $to->id)->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft)
        ->and(AuditLog::query()->where('description', 'inventory.transfer.dispatched')->count())->toBe(0);
});

it('refuses dispatch when duplicate lines for one variant exceed the source availability', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000', 'available_quantity' => '5.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '3.000']);
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '3.000']);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('refuses dispatch when the source and destination are the same warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $transfer = StockTransfer::factory()->for($warehouse, 'fromWarehouse')->for($warehouse, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '1.000']);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('refuses dispatch when the destination warehouse is inactive', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create(['is_active' => false]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '5.000', 'available_quantity' => '5.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '1.000']);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('refuses dispatch when the transfer has no items', function (): void {
    $transfer = StockTransfer::factory()->create();

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(AuditLog::query()->where('description', 'inventory.transfer.dispatched')->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('refuses to dispatch an already-received transfer', function (): void {
    $transfer = StockTransfer::factory()->confirmed()->create();
    $transfer->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'quantity' => '2.000',
    ]);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('keeps the ledger balanced: total moved out of the source equals total moved into the destination', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variantA)->for($from)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);
    InventoryStock::factory()->for($variantB)->for($from)->create(['on_hand_quantity' => '6.000', 'reserved_quantity' => '0.000', 'available_quantity' => '6.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variantA->id, 'quantity' => '5.000']);
    $transfer->items()->create(['product_variant_id' => $variantB->id, 'quantity' => '6.000']);

    $actor = User::factory()->create();
    stockTransferService()->dispatch($transfer, $actor);
    stockTransferService()->receive($transfer, $actor);

    $movements = InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->get();

    $outTotal = (float) $movements->where('warehouse_id', $from->id)->sum('quantity');
    $inTotal = (float) $movements->where('warehouse_id', $to->id)->sum('quantity');

    expect($outTotal)->toBe(-11.0)
        ->and($inTotal)->toBe(11.0)
        ->and($outTotal + $inTotal)->toBe(0.0);
});

it('exposes the parent transfer relation from an item', function (): void {
    $transfer = StockTransfer::factory()->create();
    $item = $transfer->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'quantity' => '1.000',
    ]);

    expect($item->transfer->is($transfer))->toBeTrue();
});

it('keeps confirm as a backward-compatible dispatch alias', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
    ]);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
    ]);

    stockTransferService()->confirm($transfer, User::factory()->create());

    expect($transfer->fresh()->status)->toBe(TransferStatus::Dispatched);
});

it('rejects receipt before a transfer has been dispatched', function (): void {
    $transfer = StockTransfer::factory()->create();

    expect(fn () => stockTransferService()->receive($transfer, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.not_dispatched'));
});

it('rejects transfers of inactive variants', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create(['is_active' => false]);
    InventoryStock::factory()->for($variant)->for($from)->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
    ]);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 1,
    ]);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.inactive_variant'));
});

it('requires one valid device identity for every serialized transfer line', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    InventoryStock::factory()->for($variant)->for($from)->create([
        'on_hand_quantity' => 2,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 2,
    ]);
    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
    ]);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.serials_required'));

    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $to->getKey(),
        'status' => SerializedInventoryUnitStatus::Available,
    ]);
    $transfer->items()->delete();
    $transfer->items()->create([
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'quantity' => 1,
    ]);

    expect(fn () => stockTransferService()->dispatch($transfer, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.invalid_serial'));
});

it('rechecks serialized status during dispatch and receipt transitions', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $to->getKey(),
        'status' => SerializedInventoryUnitStatus::Pending,
    ]);
    $transfer = StockTransfer::factory()->dispatched()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $item = StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->getKey(),
        'product_variant_id' => $variant->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'quantity' => 1,
    ]);
    $service = stockTransferService();
    $dispatchUnit = new ReflectionMethod($service, 'dispatchSerializedUnit');

    expect(fn (): mixed => $dispatchUnit->invoke($service, $item, $from->getKey()))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.invalid_serial'));

    expect(fn () => $service->receive($transfer, User::factory()->create()))
        ->toThrow(DomainException::class, __('admin.inventory.transfer.errors.invalid_serial'));
});

it('guards legacy nonnumeric transfer balance values', function (): void {
    $service = stockTransferService();
    $currentBalances = new ReflectionMethod($service, 'currentBalances');
    $decimal = new ReflectionMethod($service, 'decimal');
    $legacyItem = new StockTransferItem;
    $legacyItem->setRawAttributes(['product_variant_id' => 'legacy']);

    expect($currentBalances->invoke(
        $service,
        new Collection([$legacyItem]),
        1,
        2,
    ))->toBe([])
        ->and(fn (): mixed => $decimal->invoke($service, new stdClass))
        ->toThrow(DomainException::class, 'Inventory quantities must be numeric.');
});
