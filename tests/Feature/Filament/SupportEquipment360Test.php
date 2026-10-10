<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Enums\WarrantyEntitlementState;
use App\Filament\Resources\SupportEquipment\Pages\ListSupportEquipment;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Models\CustomerProfile;
use App\Models\MaintenanceSchedule;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows customer equipment to support without exposing inventory edit actions', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();

    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ListSupportEquipment::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$unit]);

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Equipment identity')
        ->assertSee($unit->serial_number)
        ->assertSee($customer->company_name)
        ->assertSee('Reliability');
});

it('shows the equipment current support entitlement on Equipment 360', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ]);

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSee('No active support entitlement');

    $level = SupportServiceLevel::query()->create(['code' => 'PRIORITY', 'name' => 'Priority', 'is_active' => true]);
    $endsOn = today()->addMonth();
    $entitlement = SupportEntitlement::query()->create([
        'customer_id' => $customer->id,
        'support_service_level_id' => $level->id,
        'serialized_inventory_unit_id' => $unit->id,
        'starts_on' => today()->subMonth(),
        'ends_on' => $endsOn,
        'status' => SupportEntitlementStatus::Active,
    ])->fresh();

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Priority')
        ->assertSee($entitlement->ends_on->locale(app()->getLocale())->translatedFormat('M j, Y'))
        ->assertDontSee('No active support entitlement');

    expect($entitlement->ends_on->toDateString())->toBe($endsOn->toDateString())
        ->and($entitlement->status)->toBe(SupportEntitlementStatus::Active);
});

// Release QA 2026-10-10: Equipment 360 showed the next preventive date as a raw "2026-09-25 00:00:00" timestamp.
it('shows the next preventive maintenance as a localized date', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ]);
    MaintenanceSchedule::factory()->create([
        'serialized_inventory_unit_id' => $unit->getKey(),
        'customer_id' => $customer->getKey(),
        'next_due_on' => '2026-09-25',
        'created_by' => $manager->getKey(),
    ]);
    app()->setLocale('ar');

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSeeHtml('<dd class="mt-1">سبتمبر 25, 2026</dd>')
        ->assertDontSee('2026-09-25 00:00:00');
});

it('shows the current warranty state on the equipment list and a placeholder for legacy units', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $customer = CustomerProfile::factory()->create();
    $custody = [
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ];
    $legacyUnit = SerializedInventoryUnit::factory()->create($custody);
    $warrantedUnit = SerializedInventoryUnit::factory()->create($custody);
    WarrantyEntitlement::factory()->create([
        'serialized_inventory_unit_id' => $warrantedUnit->id,
        'state' => WarrantyEntitlementState::PendingActivation,
    ]);

    Livewire::actingAs($manager)
        ->test(ListSupportEquipment::class)
        ->assertCanSeeTableRecords([$legacyUnit, $warrantedUnit])
        ->assertTableColumnFormattedStateSet('currentWarrantyEntitlement.state', 'Pending Activation', $warrantedUnit)
        ->assertTableColumnStateSet('currentWarrantyEntitlement.state', null, $legacyUnit)
        ->assertSee('Legacy / not configured');
});

it('formats the warranty state column defensively when it is asked to format a missing state', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $column = Livewire::actingAs($manager)
        ->test(ListSupportEquipment::class)
        ->instance()
        ->getTable()
        ->getColumn('currentWarrantyEntitlement.state');

    expect($column->formatState(null))->toBeNull()
        ->and($column->formatState(WarrantyEntitlementState::Active))->toBe('Active')
        ->and($column->getPlaceholder())->toBe('Legacy / not configured');
});
