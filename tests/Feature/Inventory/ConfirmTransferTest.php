<?php

declare(strict_types=1);

use App\Enums\TransferStatus;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function confirmTransferService(): StockTransferService
{
    return app(StockTransferService::class);
}

it('confirms a transfer, moving stock by exactly the line quantity and writing a paired movement', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '4.000']);

    $actor = User::factory()->create();

    confirmTransferService()->confirm($transfer, $actor);

    $transfer->refresh();

    expect($transfer->status)->toBe(TransferStatus::Confirmed)
        ->and($transfer->transfer_number)->not->toBeNull();

    $sourceStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $from->id)->firstOrFail();
    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $to->id)->firstOrFail();

    expect((float) $sourceStock->on_hand_quantity)->toBe(6.0)
        ->and((float) $sourceStock->available_quantity)->toBe(6.0)
        ->and((float) $destinationStock->on_hand_quantity)->toBe(4.0)
        ->and((float) $destinationStock->available_quantity)->toBe(4.0);

    $movements = InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->get();

    expect($movements)->toHaveCount(2)
        ->and($movements->firstWhere('warehouse_id', $from->id)?->quantity)->toEqual(-4.0)
        ->and($movements->firstWhere('warehouse_id', $to->id)?->quantity)->toEqual(4.0);

    $auditLog = AuditLog::query()->where('entity_type', StockTransfer::class)->where('entity_id', $transfer->id)
        ->where('action', 'inventory.transfer.confirmed')->firstOrFail();

    expect($auditLog->actor_user_id)->toBe($actor->id)
        ->and($auditLog->actor->is($actor))->toBeTrue()
        ->and($auditLog->source_channel)->toBe('dashboard');
});

it('sums duplicate lines for the same variant when checking availability but writes a movement pair per line', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '10.000', 'reserved_quantity' => '0.000', 'available_quantity' => '10.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '3.000']);
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '4.000']);

    confirmTransferService()->confirm($transfer, User::factory()->create());

    $sourceStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $from->id)->firstOrFail();
    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $to->id)->firstOrFail();

    expect((float) $sourceStock->on_hand_quantity)->toBe(3.0)
        ->and((float) $destinationStock->on_hand_quantity)->toBe(7.0);

    expect(InventoryMovement::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->count())->toBe(4);
});

it('establishes a destination balance for a variant with no existing stock row', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '7.000', 'reserved_quantity' => '0.000', 'available_quantity' => '7.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '7.000']);

    confirmTransferService()->confirm($transfer, User::factory()->create());

    $destinationStock = InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $to->id)->firstOrFail();

    expect((float) $destinationStock->on_hand_quantity)->toBe(7.0)
        ->and((float) $destinationStock->reserved_quantity)->toBe(0.0);
});

it('refuses confirmation when the source lacks enough available stock, leaving nothing changed', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '2.000', 'reserved_quantity' => '0.000', 'available_quantity' => '2.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '5.000']);

    expect(fn () => confirmTransferService()->confirm($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and(InventoryStock::query()->where('product_variant_id', $variant->id)->where('warehouse_id', $from->id)->first()?->on_hand_quantity)->toEqual(2.0)
        ->and(InventoryStock::query()->where('warehouse_id', $to->id)->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft)
        ->and(AuditLog::query()->where('action', 'inventory.transfer.confirmed')->count())->toBe(0);
});

it('refuses confirmation when duplicate lines for one variant sum to more than the source has available', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '5.000', 'reserved_quantity' => '0.000', 'available_quantity' => '5.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '3.000']);
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '3.000']);

    expect(fn () => confirmTransferService()->confirm($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('refuses confirmation when the source and destination are the same warehouse', function (): void {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->create();
    $transfer = StockTransfer::factory()->for($warehouse, 'fromWarehouse')->for($warehouse, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '1.000']);

    expect(fn () => confirmTransferService()->confirm($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('refuses confirmation when the destination warehouse is inactive', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create(['is_active' => false]);
    $variant = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variant)->for($from)->create(['on_hand_quantity' => '5.000', 'available_quantity' => '5.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variant->id, 'quantity' => '1.000']);

    expect(fn () => confirmTransferService()->confirm($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('refuses confirmation when the transfer has no items', function (): void {
    $transfer = StockTransfer::factory()->create();

    expect(fn () => confirmTransferService()->confirm($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(AuditLog::query()->where('action', 'inventory.transfer.confirmed')->count())->toBe(0)
        ->and($transfer->fresh()->status)->toBe(TransferStatus::Draft);
});

it('refuses to confirm an already-confirmed transfer', function (): void {
    $transfer = StockTransfer::factory()->confirmed()->create();
    $transfer->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'quantity' => '2.000',
    ]);

    expect(fn () => confirmTransferService()->confirm($transfer, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect(InventoryMovement::query()->count())->toBe(0);
});

it('keeps the ledger balanced: total moved out of the source equals total moved into the destination', function (): void {
    $from = Warehouse::factory()->create();
    $to = Warehouse::factory()->create();
    $variantA = ProductVariant::factory()->create();
    $variantB = ProductVariant::factory()->create();
    InventoryStock::factory()->for($variantA)->for($from)->create(['on_hand_quantity' => '10.000', 'available_quantity' => '10.000']);
    InventoryStock::factory()->for($variantB)->for($from)->create(['on_hand_quantity' => '6.000', 'available_quantity' => '6.000']);

    $transfer = StockTransfer::factory()->for($from, 'fromWarehouse')->for($to, 'toWarehouse')->create();
    $transfer->items()->create(['product_variant_id' => $variantA->id, 'quantity' => '5.000']);
    $transfer->items()->create(['product_variant_id' => $variantB->id, 'quantity' => '6.000']);

    confirmTransferService()->confirm($transfer, User::factory()->create());

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
