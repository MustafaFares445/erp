<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Filament\Resources\InventoryConditionChanges\InventoryConditionChangeResource;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\InventoryConditionBalance;
use App\Models\InventoryConditionChange;
use App\Models\InventoryOperation;
use App\Models\InventoryReservation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockAvailabilityExplainer;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

function stockAvailabilityViewer(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        InventoryPermission::StockView->value,
        InventoryPermission::ReservationView->value,
        InventoryPermission::ConditionChangeView->value,
    ]);

    return $user;
}

it('renders the availability breakdown modal with the explainer causes and resolvable document links', function (): void {
    $admin = stockAvailabilityViewer();
    $actor = User::factory()->create();

    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $stock = InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '2.000000',
        'available_quantity' => '8.000000',
    ]);

    foreach ([
        [StockCondition::Saleable, '8.000000', '0.000000'],
        [StockCondition::Quarantine, '0.000000', '0.000000'],
        [StockCondition::Damaged, '2.000000', '0.000000'],
    ] as [$condition, $onHand, $reserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    $damage = InventoryConditionChange::query()->forceCreate([
        'document_number' => 'ICC-BREAKDOWN-0001',
        'type' => InventoryConditionChangeType::Damage,
        'status' => InventoryConditionChangeStatus::Posted,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'condition_from' => StockCondition::Saleable,
        'condition_to' => StockCondition::Damaged,
        'base_quantity' => '2.000000',
        'reason_category' => ConditionChangeReason::DamagedInTransit,
        'reason' => 'Dropped in the aisle',
        'created_by' => $actor->getKey(),
    ]);

    // The table action opens without error for a permitted viewer (Filament renders a
    // modal-content action's body only inside a lazily-loaded Livewire partial, which the
    // testing harness cannot inspect directly — so the exact HTML the action feeds into that
    // partial is asserted below, against the same explainer output and shared blade view the
    // action itself uses).
    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->assertActionVisible(TestAction::make('availability_breakdown')->table($stock))
        ->mountAction(TestAction::make('availability_breakdown')->table($stock));

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);
    $html = view('filament.inventory.stock-availability-breakdown', ['explanation' => $explanation])->render();

    expect($html)->toContain(__('admin.inventory.stock.damaged_quantity'))
        ->and($html)->toContain($damage->document_number)
        ->and($html)->toContain(InventoryConditionChangeResource::getUrl('view', ['record' => $damage]));

    $this->actingAs($admin)
        ->get(InventoryConditionChangeResource::getUrl('view', ['record' => $damage]))
        ->assertOk();
});

it('links a reserved cause back to a resolvable reservation record', function (): void {
    $admin = stockAvailabilityViewer();

    $variant = ProductVariant::factory()->create();
    $warehouse = Warehouse::factory()->create();

    $stock = InventoryStock::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '3.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '7.000000',
    ]);

    foreach ([
        [StockCondition::Saleable, '10.000000', '3.000000'],
        [StockCondition::Quarantine, '0.000000', '0.000000'],
        [StockCondition::Damaged, '0.000000', '0.000000'],
    ] as [$condition, $onHand, $reserved]) {
        InventoryConditionBalance::query()->forceCreate([
            'product_variant_id' => $variant->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'stock_condition' => $condition->value,
            'on_hand_base_quantity' => $onHand,
            'reserved_base_quantity' => $reserved,
        ]);
    }

    $operation = InventoryOperation::factory()->delivery()->done()->create([
        'source_warehouse_id' => $warehouse->getKey(),
    ]);
    $reservation = InventoryReservation::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'source_type' => 'inventory_operation',
        'source_id' => $operation->getKey(),
        'base_quantity' => '3.000000',
        'expires_at' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(ListStockLevels::class)
        ->mountAction(TestAction::make('availability_breakdown')->table($stock));

    $explanation = app(StockAvailabilityExplainer::class)->explain($variant, $warehouse);
    $html = view('filament.inventory.stock-availability-breakdown', ['explanation' => $explanation])->render();

    expect($html)->toContain(__('admin.inventory.stock.reserved_quantity'))
        ->and($html)->toContain(InventoryReservationResource::getUrl('view', ['record' => $reservation]));

    $this->actingAs($admin)
        ->get(InventoryReservationResource::getUrl('view', ['record' => $reservation]))
        ->assertOk();
});

it('denies stock viewers without permission from seeing the stock levels table at all', function (): void {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get(StockLevelResource::getUrl('index'))
        ->assertForbidden();
});
