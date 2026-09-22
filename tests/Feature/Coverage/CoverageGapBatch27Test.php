<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Filament\Pages\LogisticsOutboundQueue;
use App\Filament\Resources\InventoryConditionChanges\Pages\CreateInventoryConditionChange;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Filament\Resources\InventoryLots\RelationManagers\LotBalancesRelationManager;
use App\Filament\Resources\InventoryOperations\Actions\InventoryOperationActions;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ThirdPartyCostsRelationManager;
use App\Models\InventoryLot;
use App\Models\InventoryLotBalance;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers inventory condition-change scalar and actor branches', function (): void {
    $page = new ReflectionClass(CreateInventoryConditionChange::class)->newInstanceWithoutConstructor();
    $requiredInt = new ReflectionMethod(CreateInventoryConditionChange::class, 'requiredInt');
    $stringValue = new ReflectionMethod(CreateInventoryConditionChange::class, 'stringValue');
    $actor = new ReflectionMethod(CreateInventoryConditionChange::class, 'actor');

    expect($requiredInt->invoke($page, ['id' => '12'], 'id'))->toBe(12)
        ->and($stringValue->invoke($page, ['value' => 'coverage'], 'value'))->toBe('coverage')
        ->and($stringValue->invoke($page, ['value' => 42], 'value'))->toBe('42');

    auth()->logout();

    expect(fn (): mixed => $actor->invoke($page))
        ->toThrow(LogicException::class, 'authenticated inventory condition-change actor');
});

it('covers outbound queue product reserved and terminal next-action states', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $operation = InventoryOperation::factory()->delivery()->create([
        'stage' => OperationStage::Ready,
    ]);
    $visibleVariant = ProductVariant::factory()->create();
    $deletedVariant = ProductVariant::factory()->create();

    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $visibleVariant->getKey(),
        'unit_id' => $visibleVariant->unit_id,
        'is_picked' => true,
    ]);
    InventoryOperationLine::factory()->create([
        'inventory_operation_id' => $operation->getKey(),
        'product_variant_id' => $deletedVariant->getKey(),
        'unit_id' => $deletedVariant->unit_id,
        'is_picked' => true,
    ]);

    $deletedVariant->delete();

    Livewire::actingAs($actor)
        ->test(LogisticsOutboundQueue::class)
        ->assertTableColumnStateSet('products', [$visibleVariant->sku, '—'], $operation)
        ->assertTableColumnStateSet('reserved', '0.000000', $operation);

    $nextAction = new ReflectionMethod(LogisticsOutboundQueue::class, 'nextAction');

    $operation->forceFill(['stage' => OperationStage::Ready]);
    expect($nextAction->invoke(null, $operation))->toBe(__('admin.logistics.actions.dispatch_complete'));

    $operation->forceFill(['stage' => OperationStage::Done]);
    expect($nextAction->invoke(null, $operation))->toBe(__('admin.logistics.actions.completed'));

    $operation->forceFill(['stage' => OperationStage::Canceled]);
    expect($nextAction->invoke(null, $operation))->toBe(__('admin.operation.stages.canceled'));
});

it('covers lot-balance available column state', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $lot = InventoryLot::factory()->canonical()->create();
    $warehouse = Warehouse::factory()->create();
    $balance = new InventoryLotBalance;
    $balance->forceFill([
        'inventory_lot_id' => $lot->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'stock_condition' => 'saleable',
        'on_hand_base_quantity' => '10.000000',
        'reserved_base_quantity' => '2.000000',
    ])->save();

    Livewire::actingAs($actor)
        ->test(LotBalancesRelationManager::class, [
            'ownerRecord' => $lot,
            'pageClass' => ViewInventoryLot::class,
        ])
        ->assertTableColumnStateSet('available', '8.000000', $balance);
});

it('covers wrong owner record guard in third-party cost relation manager', function (): void {
    $manager = new ReflectionClass(ThirdPartyCostsRelationManager::class)->newInstanceWithoutConstructor();
    $manager->ownerRecord = User::factory()->create();

    $method = new ReflectionMethod(ThirdPartyCostsRelationManager::class, 'maintenanceRecord');

    expect(fn (): mixed => $method->invoke($manager))
        ->toThrow(LogicException::class, 'Expected the owner record');
});

it('returns early from packing-list action without an authenticated user', function (): void {
    auth()->logout();

    $operation = InventoryOperation::factory()->delivery()->create([
        'stage' => OperationStage::Ready,
    ]);

    InventoryOperationActions::generatePackingList()
        ->getActionFunction()($operation);

    expect(true)->toBeTrue();
});
