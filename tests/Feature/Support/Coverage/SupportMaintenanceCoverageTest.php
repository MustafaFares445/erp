<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Filament\Resources\MaintenanceRequests\Actions\MaintenanceBillingActions;
use App\Filament\Resources\MaintenanceRequests\Actions\MaintenanceTransitionActions;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Filament\Resources\ServiceRecords\RelationManagers\ConsumedPartsRelationManager;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceRecordPart;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\User;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\ServiceRecordPartService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

/** @return array{0: InventoryStock, 1: MaintenanceTask, 2: InventoryLot} */
function coverageStockedServiceRecord(float $onHand = 10.0): array
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

it('reads job revenue from an already loaded invoice relation when computing the margin', function (): void {
    $invoice = Invoice::factory()->create(['total_amount' => '123.45']);
    $record = MaintenanceRecord::factory()->create(['invoice_id' => $invoice->getKey()]);

    $loaded = MaintenanceRecord::query()->with('invoice')->findOrFail($record->getKey());
    expect($loaded->relationLoaded('invoice'))->toBeTrue();

    $margin = app(MaintenanceCostService::class)->marginFor($loaded);

    expect($margin['revenue_minor'])->toBe(12345)
        ->and($margin['cost_minor'])->toBe(0)
        ->and($margin['margin_minor'])->toBe(12345);

    $withoutInvoice = MaintenanceRecord::factory()->create(['invoice_id' => null])->refresh()->load('invoice');

    expect(app(MaintenanceCostService::class)->marginFor($withoutInvoice)['revenue_minor'])->toBe(0);
});

it('requires an authenticated user in the maintenance transition and billing actions', function (): void {
    expect(auth()->user())->toBeNull();

    $transition = new ReflectionMethod(MaintenanceTransitionActions::class, 'currentActor');
    $billing = new ReflectionMethod(MaintenanceBillingActions::class, 'currentActor');

    expect(fn (): mixed => $transition->invoke(null))->toThrow(LogicException::class, 'An authenticated User is required.')
        ->and(fn (): mixed => $billing->invoke(null))->toThrow(LogicException::class, 'An authenticated User is required.');

    $user = User::factory()->admin()->create();
    $this->actingAs($user);

    expect($transition->invoke(null)->is($user))->toBeTrue()
        ->and($billing->invoke(null)->is($user))->toBeTrue();
});

it('shows no customer on Equipment 360 for equipment outside customer custody', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Warehouse,
    ]);
    $level = SupportServiceLevel::query()->create(['code' => 'WH', 'name' => 'Warehouse Plan', 'is_active' => true]);
    SupportEntitlement::query()->create([
        'customer_id' => CustomerProfile::factory()->create()->id,
        'support_service_level_id' => $level->id,
        'serialized_inventory_unit_id' => $unit->id,
        'starts_on' => today()->subMonth(),
        'ends_on' => null,
        'status' => SupportEntitlementStatus::Active,
    ]);

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Not currently in customer custody')
        ->assertSee($unit->serial_number);
});

it('selects the open-ended active support entitlement and ignores expired, suspended and future ones', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ]);

    $entitlement = static function (string $code, string $name, SupportEntitlementStatus $status, mixed $startsOn, mixed $endsOn) use ($customer, $unit): void {
        $level = SupportServiceLevel::query()->create(['code' => $code, 'name' => $name, 'is_active' => true]);
        SupportEntitlement::query()->create([
            'customer_id' => $customer->id,
            'support_service_level_id' => $level->id,
            'serialized_inventory_unit_id' => $unit->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'status' => $status,
        ]);
    };

    $entitlement('EXPIRED', 'Lapsed Plan', SupportEntitlementStatus::Active, today()->subYear(), today()->subDay());
    $entitlement('SUSPENDED', 'Paused Plan', SupportEntitlementStatus::Suspended, today()->subMonth(), today()->addMonth());
    $entitlement('FUTURE', 'Upcoming Plan', SupportEntitlementStatus::Active, today()->addDay(), today()->addYear());

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSee('No active support entitlement')
        ->assertDontSee('Lapsed Plan')
        ->assertDontSee('Paused Plan')
        ->assertDontSee('Upcoming Plan');

    $entitlement('OPEN', 'Open Ended Plan', SupportEntitlementStatus::Active, today()->subMonth(), null);

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Open Ended Plan')
        ->assertDontSee('No active support entitlement')
        ->assertDontSee('Lapsed Plan')
        ->assertDontSee('Paused Plan')
        ->assertDontSee('Upcoming Plan');
});

it('refuses to build Equipment 360 data for a record that is not serialized equipment', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
    ]);

    $component = Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->instance();

    $component->record = User::factory()->create();

    $equipment = new ReflectionMethod($component, 'equipment');

    expect(fn (): mixed => $equipment->invoke($component))
        ->toThrow(LogicException::class, 'Expected serialized equipment.');
});

it('ignores a consume-part submission whose required identifiers are not numeric', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');
    [, $task] = coverageStockedServiceRecord();

    $component = Livewire::actingAs($manager)
        ->test(ConsumedPartsRelationManager::class, [
            'ownerRecord' => $task,
            'pageClass' => ViewServiceRecord::class,
        ]);

    $relationManager = $component->instance();
    $action = collect($relationManager->getTable()->getHeaderActions())
        ->first(static fn (mixed $candidate): bool => $candidate->getName() === 'consumePart');
    expect($action)->not->toBeNull();

    // Filament's required() rules normally stop this; the guard is a defensive no-op.
    $action->call(['data' => [
        'product_variant_id' => 'not-a-number',
        'warehouse_id' => null,
        'quantity' => 'abc',
    ]]);

    expect(ServiceRecordPart::query()->where('maintenance_task_id', $task->id)->count())->toBe(0);
});

it('notifies instead of failing when a consumed part is reversed twice', function (): void {
    $admin = User::factory()->admin()->create();
    [$stock, $task, $lot] = coverageStockedServiceRecord(10.0);
    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 3.0, $admin, $lot->getKey());

    $component = Livewire::actingAs($admin)
        ->test(ConsumedPartsRelationManager::class, [
            'ownerRecord' => $task,
            'pageClass' => ViewServiceRecord::class,
        ])
        ->callAction(TestAction::make('reverse')->table($part));

    expect($part->refresh()->reversed_at)->not->toBeNull()
        ->and($stock->refresh()->available_quantity)->toEqualWithDelta(10.0, 0.001);

    $applyReversal = new ReflectionMethod(ConsumedPartsRelationManager::class, 'applyReversal');
    $applyReversal->invoke(null, $part);

    $component->assertNotified('Unable to reverse this consumption');

    expect($stock->refresh()->available_quantity)->toEqualWithDelta(10.0, 0.001);
});

it('labels the quotation action as a revision once the request has been quoted', function (): void {
    $createQuotation = new ReflectionMethod(MaintenanceBillingActions::class, 'createQuotation');

    $unquoted = MaintenanceRecord::factory()->make(['billing_type' => MaintenanceBillingType::Unbilled]);
    $quoted = MaintenanceRecord::factory()->make(['billing_type' => MaintenanceBillingType::Quoted]);

    expect($createQuotation->invoke(null)->record($unquoted)->getLabel())->toBe(__('Create Customer Quotation'))
        ->and($createQuotation->invoke(null)->record($quoted)->getLabel())->toBe(__('Create Revised Quotation'));
});
