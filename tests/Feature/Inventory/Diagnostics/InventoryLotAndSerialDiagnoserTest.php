<?php

declare(strict_types=1);

use App\Enums\InventoryDiagnosticSeverity;
use App\Enums\InventoryPermission;
use App\Enums\SerializedCustodyType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Models\InventoryLot;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\Diagnostics\InventoryLotDiagnoser;
use App\Services\Inventory\Diagnostics\SerializedInventoryUnitDiagnoser;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('flags expired saleable lot stock without changing the lot', function (): void {
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'on_hand_quantity' => '5.000000',
        'expires_at' => today()->subDay(),
    ]);

    $before = $lot->fresh()->expires_at?->toDateString();
    $diagnosis = app(InventoryLotDiagnoser::class)->diagnose($lot);

    expect($diagnosis->has('lot.expired_with_stock'))->toBeTrue()
        ->and($diagnosis->find('lot.expired_with_stock')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($lot->fresh()->expires_at?->toDateString())->toBe($before);
});

it('explains quarantined and damaged lot quantities separately', function (): void {
    $warehouse = Warehouse::factory()->create();
    $lot = InventoryLot::factory()->create([
        'warehouse_id' => $warehouse->getKey(),
        'on_hand_quantity' => '8.000000',
        'expires_at' => today()->addYear(),
    ]);

    $lot->conditionBalances()->where('stock_condition', StockCondition::Quarantine->value)
        ->update(['on_hand_base_quantity' => '2.000000']);
    $lot->conditionBalances()->where('stock_condition', StockCondition::Damaged->value)
        ->update(['on_hand_base_quantity' => '1.000000']);

    $diagnosis = app(InventoryLotDiagnoser::class)->diagnose($lot->fresh());

    expect($diagnosis->codes())->toContain('lot.quarantine', 'lot.damaged')
        ->and($diagnosis->find('lot.quarantine')?->severity)->toBe(InventoryDiagnosticSeverity::Warning)
        ->and($diagnosis->find('lot.damaged')?->severity)->toBe(InventoryDiagnosticSeverity::Warning);
});

it('detects a serialized warehouse custody contradiction without modifying custody', function (): void {
    $serial = SerializedInventoryUnit::factory()->create([
        'warehouse_id' => null,
        'custody_type' => SerializedCustodyType::Warehouse,
        'status' => SerializedInventoryUnitStatus::Available,
        'stock_condition' => StockCondition::Saleable,
    ]);

    $diagnosis = app(SerializedInventoryUnitDiagnoser::class)->diagnose($serial);

    expect($diagnosis->find('serial.custody_contradiction')?->severity)->toBe(InventoryDiagnosticSeverity::Blocker)
        ->and($serial->fresh()->warehouse_id)->toBeNull();
});

it('explains a pending serialized unit without pretending it was received', function (): void {
    $serial = SerializedInventoryUnit::factory()->create([
        'warehouse_id' => null,
        'status' => SerializedInventoryUnitStatus::Pending,
        'custody_type' => SerializedCustodyType::Unknown,
        'stock_condition' => StockCondition::Saleable,
    ]);

    $diagnosis = app(SerializedInventoryUnitDiagnoser::class)->diagnose($serial);

    expect($diagnosis->codes())->toContain('serial.pending_receipt')
        ->and($diagnosis->checks)->not->toBeEmpty()
        ->and($serial->fresh()->status)->toBe(SerializedInventoryUnitStatus::Pending);
});

it('renders lot operational diagnosis on the actual Filament View screen', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(static fn (InventoryPermission $permission): string => $permission->value, InventoryPermission::cases()));
    $lot = InventoryLot::factory()->create(['expires_at' => today()->subDay()]);

    Livewire::actingAs($user)
        ->test(ViewInventoryLot::class, ['record' => $lot->getKey()])
        ->assertOk()
        ->assertSee(__('inventory_view_ux.title'));
});
