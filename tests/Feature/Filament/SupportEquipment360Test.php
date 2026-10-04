<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Filament\Resources\SupportEquipment\Pages\ListSupportEquipment;
use App\Filament\Resources\SupportEquipment\Pages\ViewSupportEquipment;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\User;
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
    SupportEntitlement::query()->create([
        'customer_id' => $customer->id,
        'support_service_level_id' => $level->id,
        'serialized_inventory_unit_id' => $unit->id,
        'starts_on' => today()->subMonth(),
        'ends_on' => today()->addMonth(),
        'status' => SupportEntitlementStatus::Active,
    ]);

    Livewire::actingAs($manager)
        ->test(ViewSupportEquipment::class, ['record' => $unit->getRouteKey()])
        ->assertSee('Priority')
        ->assertSee(today()->addMonth()->toDateString())
        ->assertDontSee('No active support entitlement');
});
