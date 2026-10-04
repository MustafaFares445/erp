<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\SupportEntitlementStatus;
use App\Filament\Resources\SlaCalendars\Pages\CreateSlaCalendar;
use App\Filament\Resources\SlaCalendars\Pages\EditSlaCalendar;
use App\Filament\Resources\SlaCalendars\Pages\ListSlaCalendars;
use App\Filament\Resources\SlaCalendars\Schemas\SlaCalendarForm;
use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use App\Filament\Resources\SupportEntitlements\Pages\CreateSupportEntitlement;
use App\Filament\Resources\SupportEntitlements\Pages\EditSupportEntitlement;
use App\Filament\Resources\SupportEntitlements\Pages\ListSupportEntitlements;
use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use App\Filament\Resources\SupportServiceLevels\Pages\CreateSupportServiceLevel;
use App\Filament\Resources\SupportServiceLevels\Pages\EditSupportServiceLevel;
use App\Filament\Resources\SupportServiceLevels\Pages\ListSupportServiceLevels;
use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\SlaCalendar;
use App\Models\SupportEntitlement;
use App\Models\SupportServiceLevel;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function supportUserWithRole(string $role): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole($role);

    return $user;
}

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();

    $this->manager = supportUserWithRole('Support Manager');
});

it('lets support managers create a calendar with weekly periods and holidays', function (): void {
    Livewire::actingAs($this->manager)
        ->test(CreateSlaCalendar::class)
        ->fillForm([
            'name' => 'Office hours',
            'timezone' => 'Asia/Riyadh',
            'is_24x7' => false,
            'is_default' => false,
            'is_active' => true,
            'periods' => [
                ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00'],
                ['weekday' => 1, 'starts_at' => '13:00', 'ends_at' => '17:00'],
            ],
            'exceptions' => [
                ['date' => '2026-12-25', 'name' => 'Holiday', 'is_working_day' => false],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $calendar = SlaCalendar::query()->where('name', 'Office hours')->firstOrFail();

    expect($calendar->periods)->toHaveCount(2)
        ->and($calendar->exceptions)->toHaveCount(1)
        ->and($calendar->timezone)->toBe('Asia/Riyadh');
});

it('rejects overlapping or inverted weekly periods and duplicate exception dates', function (): void {
    Livewire::actingAs($this->manager)
        ->test(CreateSlaCalendar::class)
        ->fillForm([
            'name' => 'Broken',
            'timezone' => 'UTC',
            'periods' => [
                ['weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '13:00'],
                ['weekday' => 2, 'starts_at' => '12:00', 'ends_at' => '17:00'],
            ],
            'exceptions' => [
                ['date' => '2026-12-25', 'is_working_day' => false],
                ['date' => '2026-12-25', 'is_working_day' => false],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['periods', 'exceptions']);

    Livewire::actingAs($this->manager)
        ->test(CreateSlaCalendar::class)
        ->fillForm([
            'name' => 'Inverted',
            'timezone' => 'UTC',
            'periods' => [['weekday' => 3, 'starts_at' => '17:00', 'ends_at' => '09:00']],
        ])
        ->call('create')
        ->assertHasFormErrors(['periods']);

    expect(SlaCalendar::query()->whereIn('name', ['Broken', 'Inverted'])->exists())->toBeFalse();
});

it('does not require periods for a 24x7 calendar and keeps a single default', function (): void {
    $first = SlaCalendar::query()->create(['name' => 'First', 'timezone' => 'UTC', 'is_default' => true]);

    Livewire::actingAs($this->manager)
        ->test(CreateSlaCalendar::class)
        ->fillForm(['name' => 'Always on', 'timezone' => 'UTC', 'is_24x7' => true, 'is_default' => true, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SlaCalendar::query()->where('is_default', true)->pluck('name')->all())->toBe(['Always on'])
        ->and($first->refresh()->is_default)->toBeFalse();
});

it('edits a calendar and hides delete for the default one', function (): void {
    $default = SlaCalendar::query()->create(['name' => 'Default', 'timezone' => 'UTC', 'is_default' => true]);
    $other = SlaCalendar::query()->create(['name' => 'Other', 'timezone' => 'UTC']);

    Livewire::actingAs($this->manager)
        ->test(ListSlaCalendars::class)
        ->assertCanSeeTableRecords([$default, $other])
        ->assertTableActionHidden('delete', $default)
        ->assertTableActionVisible('delete', $other);

    Livewire::actingAs($this->manager)
        ->test(EditSlaCalendar::class, ['record' => $other->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->refresh()->name)->toBe('Renamed');
});

it('validates weekly periods through the shared helper', function (): void {
    expect(SlaCalendarForm::periodsProblem([]))->toBeNull()
        ->and(SlaCalendarForm::periodsProblem(['not-a-row', ['starts_at' => '09:00']]))->toBeNull()
        ->and(SlaCalendarForm::periodsProblem([
            ['weekday' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00'],
            ['weekday' => 2, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00'],
            ['weekday' => 1, 'starts_at' => '12:00:00', 'ends_at' => '17:00:00'],
        ]))->toBeNull()
        ->and(SlaCalendarForm::periodsProblem([
            ['weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:00'],
        ]))->not->toBeNull()
        ->and(SlaCalendarForm::weekdayOptions())->toHaveCount(7);
});

it('manages service levels with unique codes and protects those in use', function (): void {
    Livewire::actingAs($this->manager)
        ->test(CreateSupportServiceLevel::class)
        ->fillForm(['code' => 'GOLD', 'name' => 'Gold', 'description' => 'Top tier', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::actingAs($this->manager)
        ->test(CreateSupportServiceLevel::class)
        ->fillForm(['code' => 'GOLD', 'name' => 'Duplicate'])
        ->call('create')
        ->assertHasFormErrors(['code' => 'unique']);

    $gold = SupportServiceLevel::query()->where('code', 'GOLD')->firstOrFail();
    $free = SupportServiceLevel::query()->create(['code' => 'FREE', 'name' => 'Free']);
    SupportEntitlement::query()->create([
        'customer_id' => CustomerProfile::factory()->create()->id,
        'support_service_level_id' => $gold->id,
        'starts_on' => '2026-01-01',
        'status' => SupportEntitlementStatus::Active,
    ]);

    Livewire::actingAs($this->manager)
        ->test(ListSupportServiceLevels::class)
        ->assertCanSeeTableRecords([$gold, $free])
        ->assertTableActionHidden('delete', $gold)
        ->assertTableActionVisible('delete', $free);

    Livewire::actingAs($this->manager)
        ->test(EditSupportServiceLevel::class, ['record' => $free->getRouteKey()])
        ->fillForm(['name' => 'Complimentary', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($free->refresh()->only(['name', 'is_active']))->toBe(['name' => 'Complimentary', 'is_active' => false]);
});

it('creates entitlements for the customer or one of their units only', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $level = SupportServiceLevel::query()->create(['code' => 'PREMIUM', 'name' => 'Premium']);
    $ownUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->id,
    ]);
    $foreignUnit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->id,
    ]);

    Livewire::actingAs($this->manager)
        ->test(CreateSupportEntitlement::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'support_service_level_id' => $level->id,
            'serialized_inventory_unit_id' => $ownUnit->id,
            'status' => SupportEntitlementStatus::Active->value,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
            'external_reference' => 'PO-77',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::actingAs($this->manager)
        ->test(CreateSupportEntitlement::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'support_service_level_id' => $level->id,
            'serialized_inventory_unit_id' => $foreignUnit->id,
            'starts_on' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['serialized_inventory_unit_id']);

    Livewire::actingAs($this->manager)
        ->test(CreateSupportEntitlement::class)
        ->fillForm([
            'customer_id' => $customer->id,
            'support_service_level_id' => $level->id,
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-01-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['ends_on']);

    Livewire::actingAs($this->manager)
        ->test(CreateSupportEntitlement::class)
        ->fillForm(['customer_id' => $customer->id, 'support_service_level_id' => $level->id, 'starts_on' => '2026-01-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    $entitlement = SupportEntitlement::query()->where('external_reference', 'PO-77')->firstOrFail();

    Livewire::actingAs($this->manager)
        ->test(ListSupportEntitlements::class)
        ->assertCanSeeTableRecords(SupportEntitlement::query()->get())
        ->filterTable('status', SupportEntitlementStatus::Active->value)
        ->assertCanSeeTableRecords([$entitlement]);

    Livewire::actingAs($this->manager)
        ->test(EditSupportEntitlement::class, ['record' => $entitlement->getRouteKey()])
        ->fillForm(['status' => SupportEntitlementStatus::Suspended->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($entitlement->refresh()->status)->toBe(SupportEntitlementStatus::Suspended)
        ->and(SupportEntitlement::query()->activeOn('2026-06-01')->count())->toBe(1);
});

it('applies the dedicated support permissions to the configuration resources', function (): void {
    $reviewer = supportUserWithRole('Reviewer');
    $agent = supportUserWithRole('Support Agent');

    foreach ([SlaCalendarResource::class, SupportServiceLevelResource::class, SupportEntitlementResource::class] as $resource) {
        $this->actingAs($this->manager);
        expect($resource::canViewAny())->toBeTrue($resource)
            ->and($resource::canCreate())->toBeTrue($resource);

        $this->actingAs($reviewer);
        expect($resource::canViewAny())->toBeTrue($resource)
            ->and($resource::canCreate())->toBeFalse($resource);

        $this->actingAs($agent);
        expect($resource::canViewAny())->toBeFalse($resource);
    }
});

it('blocks the configuration resources when SLA v2 is switched off', function (): void {
    config()->set('support.sla_v2_enabled', false);

    $this->actingAs($this->manager);

    foreach ([SlaCalendarResource::class, SupportServiceLevelResource::class, SupportEntitlementResource::class] as $resource) {
        expect($resource::canViewAny())->toBeFalse($resource)
            ->and($resource::shouldRegisterNavigation())->toBeFalse($resource);
    }

    $this->get(SlaCalendarResource::getUrl())->assertForbidden();
});
