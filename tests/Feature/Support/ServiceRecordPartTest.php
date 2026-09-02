<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Filament\Resources\ServiceRecords\RelationManagers\ConsumedPartsRelationManager;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\MaintenanceTask;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\Unit;
use App\Models\User;
use App\Policies\MaintenanceTaskPolicy;
use App\Policies\WarehousePolicy;
use App\Services\Inventory\ProductVariantUomService;
use App\Services\Support\Exceptions\InvalidStatusTransition;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function makePartsSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** @return array{0: InventoryStock, 1: MaintenanceTask, 2: InventoryLot} */
function makeStockedTask(float $onHand = 10.0): array
{
    $stock = InventoryStock::factory()->create([
        'on_hand_quantity' => $onHand,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => $onHand,
    ]);
    $lot = InventoryLot::factory()
        ->for($stock->productVariant)
        ->for($stock->warehouse)
        ->create([
            'on_hand_quantity' => (string) $onHand,
            'reserved_quantity' => '0.000000',
            'expires_at' => null,
        ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);

    return [$stock, $task, $lot];
}

it('decrements stock and creates exactly one InventoryMovement referencing the service record', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(10.0);

    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 3.0, $manager, $lot->getKey());

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(7.0, 0.001)
        ->and(InventoryMovement::query()->where('source_type', 'service_record_part')->where('source_id', $part->id)->count())->toBe(1)
        ->and((float) $part->consumptionMovement->quantity)->toBe(-3.0)
        ->and($part->consumptionMovement->transaction_quantity)->toBe('3.000000')
        ->and($part->consumptionMovement->transaction_unit_id)->toBe($stock->productVariant->unit_id)
        ->and($part->consumptionMovement->conversion_factor_snapshot)->toBe('1.000000')
        ->and($part->consumptionMovement->base_quantity_delta)->toBe('-3.000000');
});

it('keeps maintenance consumption explicitly in the variant base UOM when alternate UOMs exist', function (): void {
    $manager = makePartsSupportManager();
    $piece = Unit::factory()->whole()->create([
        'code' => 'MAINT-PIECE',
        'name' => 'Maintenance piece',
        'symbol' => 'MPC',
        'family' => 'count',
    ]);
    $box = Unit::factory()->whole()->create([
        'code' => 'MAINT-BOX',
        'name' => 'Maintenance box',
        'symbol' => 'MBX',
        'family' => 'count',
    ]);
    $variant = ProductVariant::factory()->create(['unit_id' => $piece->getKey()]);
    app(ProductVariantUomService::class)->sync($variant, [
        [
            'unit_id' => $piece->getKey(),
            'is_base' => true,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => true,
            'factor_to_base' => '1',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
        [
            'unit_id' => $box->getKey(),
            'is_base' => false,
            'is_purchase' => true,
            'is_sale' => true,
            'is_display' => false,
            'factor_to_base' => '10',
            'rounding_increment' => '1',
            'permits_cross_family_conversion' => false,
            'is_active' => true,
        ],
    ]);

    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 20,
        'reserved_quantity' => 0,
        'available_quantity' => 20,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => $stock->warehouse_id,
        'on_hand_quantity' => '20.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);

    $part = app(ServiceRecordPartService::class)->consume(
        $task,
        $variant->getKey(),
        $stock->warehouse_id,
        3,
        $manager,
        $lot->getKey(),
    );

    expect($part->quantity)->toBe('3.000000')
        ->and($part->consumptionMovement->transaction_quantity)->toBe('3.000000')
        ->and($part->consumptionMovement->transaction_unit_id)->toBe($piece->getKey())
        ->and($part->consumptionMovement->conversion_factor_snapshot)->toBe('1.000000')
        ->and($part->consumptionMovement->base_quantity_delta)->toBe('-3.000000');
});

it('rejects a consumption exceeding available stock, naming the available quantity, with no stock or movement change', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(5.0);

    expect(fn () => app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 10.0, $manager, $lot->getKey()))
        ->toThrow(ValidationException::class);

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(5.0, 0.001)
        ->and(InventoryMovement::query()->where('source_type', 'service_record_part')->count())->toBe(0)
        ->and(ServiceRecordPart::query()->count())->toBe(0);
});

it('leaves no consumption record, stock change, or movement behind when the consumption is rejected', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(2.0);

    expect(fn () => app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 5.0, $manager, $lot->getKey()))
        ->toThrow(ValidationException::class);

    expect(ServiceRecordPart::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('source_type', 'service_record_part')->count())->toBe(0)
        ->and($stock->refresh()->on_hand_quantity)->toEqualWithDelta(2.0, 0.001);
});

it('requires a lot allocation for batch-tracked maintenance consumption and restores the same lot on reversal', function (): void {
    $manager = makePartsSupportManager();
    $variant = ProductVariant::factory()->grain()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'available_quantity' => 5,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->create([
        'warehouse_id' => $stock->warehouse_id,
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $service = app(ServiceRecordPartService::class);

    expect(fn () => $service->consume($task, $variant->getKey(), $stock->warehouse_id, 1, $manager))
        ->toThrow(ValidationException::class);

    $part = $service->consume($task, $variant->getKey(), $stock->warehouse_id, 2, $manager, $lot->getKey());

    expect($stock->refresh()->on_hand_quantity)->toBe('3.000000')
        ->and($lot->conditionOnHandQuantity(StockCondition::Saleable, $stock->warehouse_id))->toBe(3.0)
        ->and($part->inventory_lot_id)->toBe($lot->getKey());

    $admin = User::factory()->admin()->create();
    $service->reverse($part, $admin);

    expect($stock->refresh()->on_hand_quantity)->toBe('5.000000')
        ->and($lot->conditionOnHandQuantity(StockCondition::Saleable, $stock->warehouse_id))->toBe(5.0);
});

it('consumes and reverses a serialized maintenance part with explicit custody', function (): void {
    $admin = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'available_quantity' => 1,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $service = app(ServiceRecordPartService::class);

    $part = $service->consume(
        $task,
        $variant->getKey(),
        $stock->warehouse_id,
        1,
        $admin,
        null,
        $unit->getKey(),
    );

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Consumed)
        ->and($unit->warehouse_id)->toBeNull()
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Maintenance)
        ->and($stock->refresh()->on_hand_quantity)->toBe('0.000000');

    $service->reverse($part, $admin);

    expect($unit->refresh()->status)->toBe(SerializedInventoryUnitStatus::Available)
        ->and($unit->warehouse_id)->toBe($stock->warehouse_id)
        ->and($unit->custody_type)->toBe(SerializedCustodyType::Warehouse)
        ->and($stock->refresh()->on_hand_quantity)->toBe('1.000000');
});

it('rejects maintenance consumption of a non-saleable serialized unit', function (): void {
    $admin = User::factory()->admin()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 1,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 0,
    ]);
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $stock->warehouse_id,
        'status' => SerializedInventoryUnitStatus::Available,
        'custody_type' => SerializedCustodyType::Warehouse,
        'stock_condition' => StockCondition::Quarantine,
    ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);

    expect(fn () => app(ServiceRecordPartService::class)->consume(
        $task,
        $variant->getKey(),
        $stock->warehouse_id,
        1,
        $admin,
        null,
        $unit->getKey(),
    ))->toThrow(ValidationException::class);

    expect(ServiceRecordPart::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('source_type', 'service_record_part')->count())->toBe(0);
});

it('rejects a non-positive quantity with its own message, distinct from the insufficient-stock message', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task] = makeStockedTask(5.0);

    try {
        app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 0.0, $manager);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors()['quantity'][0] ?? null)->toBe('The quantity must be greater than zero.');
    }

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(5.0, 0.001)
        ->and(ServiceRecordPart::query()->count())->toBe(0);
});

it('rejects a second consumption that would drive stock negative, enforced by the row lock, with no partial write', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(5.0);
    $service = app(ServiceRecordPartService::class);

    $service->consume($task, $stock->product_variant_id, $stock->warehouse_id, 5.0, $manager, $lot->getKey());

    expect(fn () => $service->consume($task, $stock->product_variant_id, $stock->warehouse_id, 1.0, $manager, $lot->getKey()))
        ->toThrow(ValidationException::class);

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(0.0, 0.001)
        ->and(ServiceRecordPart::query()->count())->toBe(1);
});

it('reverses with a compensating movement that restores stock, never editing or deleting the original, full quantity only', function (): void {
    $admin = User::factory()->admin()->create();
    [$stock, $task, $lot] = makeStockedTask(10.0);
    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 4.0, $admin, $lot->getKey());

    app(ServiceRecordPartService::class)->reverse($part, $admin);

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(10.0, 0.001)
        ->and($part->refresh()->reversed_at)->not->toBeNull()
        ->and($part->reversed_by)->toBe($admin->id)
        ->and((float) $part->quantity)->toBe(4.0)
        ->and($part->product_variant_id)->toBe($stock->product_variant_id)
        ->and((float) $part->reversalMovement->quantity)->toBe(4.0)
        ->and($part->reversalMovement->transaction_quantity)->toBe('4.000000')
        ->and($part->reversalMovement->transaction_unit_id)->toBe($stock->productVariant->unit_id)
        ->and($part->reversalMovement->base_quantity_delta)->toBe('4.000000')
        ->and($part->reversalMovement->reversal_of_movement_id)->toBe($part->inventory_movement_id);

    // A second reversal is rejected — at most once (FR-086).
    expect(fn () => app(ServiceRecordPartService::class)->reverse($part, $admin))
        ->toThrow(DomainException::class);
});

it('rejects an edit to any column other than the reversal fields, even directly on the model', function (): void {
    [, $task] = makeStockedTask();
    $part = ServiceRecordPart::factory()->create(['maintenance_task_id' => $task->id]);

    expect(fn () => $part->update(['quantity' => 999]))->toThrow(DomainException::class)
        ->and(fn () => $part->delete())->toThrow(DomainException::class);
});

it('restricts reversal to System Admin, including after the service record is closed; Support Manager is denied', function (): void {
    $admin = User::factory()->admin()->create();
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(10.0);
    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 2.0, $admin, $lot->getKey());

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Closed, $manager);

    expect(fn () => app(ServiceRecordPartService::class)->reverse($part, $manager))
        ->toThrow(AuthorizationException::class);

    app(ServiceRecordPartService::class)->reverse($part, $admin);
    expect($part->refresh()->reversed_at)->not->toBeNull();
});

it('rejects a new consumption against a closed or cancelled service record, even directly', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $closedTask] = makeStockedTask(10.0);
    $closedTask->update(['status' => MaintenanceStatus::Closed]);

    expect(fn () => app(ServiceRecordPartService::class)->consume($closedTask, $stock->product_variant_id, $stock->warehouse_id, 1.0, $manager))
        ->toThrow(InvalidStatusTransition::class);

    [$stock2, $cancelledTask] = makeStockedTask(10.0);
    $cancelledTask->update(['status' => MaintenanceStatus::Cancelled]);

    expect(fn () => app(ServiceRecordPartService::class)->consume($cancelledTask, $stock2->product_variant_id, $stock2->warehouse_id, 1.0, $manager))
        ->toThrow(InvalidStatusTransition::class);
});

it('grants no Inventory dashboard access to a user holding only the parts-consumption permission (FR-088)', function (): void {
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    expect(app(WarehousePolicy::class)->viewAny($agent))->toBeFalse()
        ->and(app(WarehousePolicy::class)->create($agent))->toBeFalse();
});

it('lists every consumed part with variant, warehouse, quantity, actor, and timestamp', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(10.0);

    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 1.5, $manager, $lot->getKey());

    $fresh = ServiceRecordPart::query()->with(['productVariant', 'warehouse', 'createdBy'])->findOrFail($part->id);

    expect($fresh->productVariant)->not->toBeNull()
        ->and($fresh->warehouse)->not->toBeNull()
        ->and((float) $fresh->quantity)->toBe(1.5)
        ->and($fresh->createdBy?->id)->toBe($manager->id)
        ->and($fresh->created_at)->not->toBeNull();
});

it('grants manage/execute-style consume ability per the role matrix, matching page-open, direct-action, and direct-service-call parity', function (): void {
    $manager = makePartsSupportManager();
    [$agent, $agentProfile] = (function (): array {
        $agent = User::factory()->admin()->create();
        $agent->assignRole('Support Agent');

        $profile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

        return [$agent, $profile];
    })();
    $admin = User::factory()->admin()->create();

    $policy = app(MaintenanceTaskPolicy::class);
    $ownTask = MaintenanceTask::factory()->create(['employee_id' => $agentProfile->id, 'status' => MaintenanceStatus::InProgress]);
    $otherTask = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);

    expect($policy->consume($manager, $otherTask))->toBeTrue()
        ->and($policy->consume($agent, $ownTask))->toBeTrue()
        ->and($policy->consume($agent, $otherTask))->toBeFalse()
        ->and($policy->reverse($admin))->toBeTrue()
        ->and($policy->reverse($manager))->toBeFalse()
        ->and($policy->reverse($agent))->toBeFalse();

    [$stock] = makeStockedTask();
    expect(fn () => app(ServiceRecordPartService::class)->consume($otherTask, $stock->product_variant_id, $stock->warehouse_id, 1.0, $agent))
        ->toThrow(AuthorizationException::class);
});

it('consumes a part through the actual relation manager "Consume Part" action', function (): void {
    $manager = makePartsSupportManager();
    [$stock, $task, $lot] = makeStockedTask(10.0);

    Livewire::actingAs($manager)
        ->test(ConsumedPartsRelationManager::class, [
            'ownerRecord' => $task,
            'pageClass' => ViewServiceRecord::class,
        ])
        ->callAction(TestAction::make('consumePart')->table(), [
            'product_variant_id' => $stock->product_variant_id,
            'warehouse_id' => $stock->warehouse_id,
            'inventory_lot_id' => $lot->getKey(),
            'quantity' => 2,
        ])
        ->assertHasNoActionErrors();

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(8.0, 0.001)
        ->and(ServiceRecordPart::query()->where('maintenance_task_id', $task->id)->count())->toBe(1);
});

it('reverses a part through the actual relation manager row action', function (): void {
    $admin = User::factory()->admin()->create();
    [$stock, $task, $lot] = makeStockedTask(10.0);
    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 3.0, $admin, $lot->getKey());

    Livewire::actingAs($admin)
        ->test(ConsumedPartsRelationManager::class, [
            'ownerRecord' => $task,
            'pageClass' => ViewServiceRecord::class,
        ])
        ->callAction(TestAction::make('reverse')->table($part));

    expect($part->refresh()->reversed_at)->not->toBeNull()
        ->and($stock->refresh()->available_quantity)->toEqualWithDelta(10.0, 0.001);
});
