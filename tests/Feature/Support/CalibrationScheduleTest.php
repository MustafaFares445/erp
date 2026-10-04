<?php

declare(strict_types=1);

use App\Data\Support\MaintenanceScheduleData;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceIntervalType;
use App\Enums\MaintenanceKind;
use App\Enums\OccurrenceStatus;
use App\Enums\SerializedCustodyType;
use App\Filament\Resources\MaintenanceSchedules\Pages\CreateMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\Pages\EditMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\Pages\ListMaintenanceSchedules;
use App\Filament\Resources\MaintenanceSchedules\Pages\ViewMaintenanceSchedule;
use App\Models\CustomerProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\NotificationDelivery;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Support\MaintenanceScheduleGenerator;
use App\Services\Support\MaintenanceScheduleService;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new NotificationTemplateSeeder)->run();
    Notification::fake();
});

function kindManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/** @return array{0: CustomerProfile, 1: SerializedInventoryUnit} */
function kindEquipment(): array
{
    $customer = CustomerProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_id' => $customer->getKey(),
    ]);

    return [$customer, $unit];
}

function kindSchedule(MaintenanceKind $kind, User $manager, int $dueInDays = 3): MaintenanceSchedule
{
    [$customer, $unit] = kindEquipment();

    return app(MaintenanceScheduleService::class)->create(new MaintenanceScheduleData(
        serializedInventoryUnitId: $unit->getKey(),
        customerId: $customer->getKey(),
        name: 'Furnace '.$kind->value,
        intervalType: MaintenanceIntervalType::Months,
        intervalValue: 6,
        leadTimeDays: 7,
        firstDueOn: now()->addDays($dueInDays)->toDateString(),
        billingType: MaintenanceBillingType::Unbilled,
        maintenanceKind: $kind,
    ), $manager);
}

it('stores the schedule kind and defaults to preventive', function (): void {
    $manager = kindManager();
    [$customer, $unit] = kindEquipment();

    $default = app(MaintenanceScheduleService::class)->create(new MaintenanceScheduleData(
        serializedInventoryUnitId: $unit->getKey(),
        customerId: $customer->getKey(),
        name: 'Quarterly service',
        intervalType: MaintenanceIntervalType::Months,
        intervalValue: 3,
        leadTimeDays: 7,
        firstDueOn: now()->addWeek()->toDateString(),
        billingType: MaintenanceBillingType::Unbilled,
    ), $manager);

    expect($default->maintenance_kind)->toBe(MaintenanceKind::Preventive)
        ->and(kindSchedule(MaintenanceKind::Calibration, $manager)->maintenance_kind)->toBe(MaintenanceKind::Calibration)
        ->and(kindSchedule(MaintenanceKind::Inspection, $manager)->fresh()->maintenance_kind)->toBe(MaintenanceKind::Inspection);
});

it('never allows corrective, installation or other work to recur on a schedule', function (): void {
    $manager = kindManager();

    foreach ([MaintenanceKind::Corrective, MaintenanceKind::Installation, MaintenanceKind::Other] as $kind) {
        expect(fn (): MaintenanceSchedule => kindSchedule($kind, $manager))->toThrow(ValidationException::class, 'preventive, inspection or calibration');
    }

    expect(MaintenanceSchedule::query()->count())->toBe(0);
});

it('rejects a new calibration schedule while calibration is disabled', function (): void {
    config(['support.calibration_enabled' => false]);

    expect(fn (): MaintenanceSchedule => kindSchedule(MaintenanceKind::Calibration, kindManager()))->toThrow(ValidationException::class, 'Calibration is not enabled');
    expect(kindSchedule(MaintenanceKind::Inspection, kindManager())->exists)->toBeTrue();
});

it('generates calibration requests that inherit the schedule kind and notify with the calibration template', function (): void {
    $manager = kindManager();
    $schedule = kindSchedule(MaintenanceKind::Calibration, $manager);

    expect(app(MaintenanceScheduleGenerator::class)->raiseDue())->toBe(1);

    $occurrence = $schedule->occurrences()->orderBy('due_on')->first()->fresh();
    $record = MaintenanceRecord::query()->findOrFail($occurrence->maintenance_record_id);

    expect($occurrence->status)->toBe(OccurrenceStatus::Raised)
        ->and($record->maintenance_kind)->toBe(MaintenanceKind::Calibration)
        ->and($record->description)->toStartWith('Calibration due: Furnace calibration')
        ->and($record->serialized_inventory_unit_id)->toBe($schedule->serialized_inventory_unit_id)
        ->and(NotificationDelivery::query()->where('template_key', 'calibration.due')->count())->toBe(1)
        ->and(NotificationDelivery::query()->where('template_key', 'maintenance.due')->count())->toBe(0);

    // A second run the same day raises nothing and sends nothing more.
    expect(app(MaintenanceScheduleGenerator::class)->raiseDue())->toBe(0)
        ->and(NotificationDelivery::query()->where('template_key', 'calibration.due')->count())->toBe(1);
});

it('keeps raising preventive and inspection requests with their own kind and the existing template', function (): void {
    $manager = kindManager();
    $preventive = kindSchedule(MaintenanceKind::Preventive, $manager);
    $inspection = kindSchedule(MaintenanceKind::Inspection, $manager);

    expect(app(MaintenanceScheduleGenerator::class)->raiseDue())->toBe(2);

    $preventiveRecord = MaintenanceRecord::query()->findOrFail($preventive->occurrences()->orderBy('due_on')->first()->maintenance_record_id);
    $inspectionRecord = MaintenanceRecord::query()->findOrFail($inspection->occurrences()->orderBy('due_on')->first()->maintenance_record_id);

    expect($preventiveRecord->maintenance_kind)->toBe(MaintenanceKind::Preventive)
        ->and($preventiveRecord->description)->toStartWith('Preventive maintenance due:')
        ->and($inspectionRecord->maintenance_kind)->toBe(MaintenanceKind::Inspection)
        ->and($inspectionRecord->description)->toStartWith('Inspection due:')
        ->and(NotificationDelivery::query()->where('template_key', 'maintenance.due')->count())->toBe(2);
});

it('leaves calibration occurrences pending while calibration is disabled but still raises other kinds', function (): void {
    $manager = kindManager();
    $calibration = kindSchedule(MaintenanceKind::Calibration, $manager);
    $preventive = kindSchedule(MaintenanceKind::Preventive, $manager);

    config(['support.calibration_enabled' => false]);

    expect(app(MaintenanceScheduleGenerator::class)->raiseDue())->toBe(1)
        ->and($calibration->occurrences()->where('status', OccurrenceStatus::Pending->value)->count())->toBeGreaterThan(0)
        ->and($calibration->occurrences()->whereNotNull('maintenance_record_id')->count())->toBe(0)
        ->and($preventive->occurrences()->whereNotNull('maintenance_record_id')->count())->toBe(1);

    config(['support.calibration_enabled' => true]);

    expect(app(MaintenanceScheduleGenerator::class)->raiseDue())->toBe(1);
});

it('lets the schedule form pick a schedule type, defaulting to preventive and locking it after creation', function (): void {
    $manager = kindManager();
    [$customer, $unit] = kindEquipment();
    $base = [
        'customer_id' => $customer->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
        'name' => 'Furnace calibration',
        'interval_type' => MaintenanceIntervalType::Months->value,
        'interval_value' => 6,
        'lead_time_days' => 14,
        'first_due_on' => now()->addMonth()->toDateString(),
        'billing_type' => MaintenanceBillingType::Unbilled->value,
    ];

    Livewire::actingAs($manager)->test(CreateMaintenanceSchedule::class)
        ->assertFormSet(['maintenance_kind' => MaintenanceKind::Preventive->value])
        ->fillForm([...$base, 'maintenance_kind' => MaintenanceKind::Calibration->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $schedule = MaintenanceSchedule::query()->sole();

    expect($schedule->maintenance_kind)->toBe(MaintenanceKind::Calibration);

    Livewire::actingAs($manager)->test(EditMaintenanceSchedule::class, ['record' => $schedule->getRouteKey()])
        ->assertFormFieldDisabled('maintenance_kind')
        ->fillForm(['name' => 'Renamed calibration'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($schedule->fresh()->maintenance_kind)->toBe(MaintenanceKind::Calibration)
        ->and($schedule->fresh()->name)->toBe('Renamed calibration');
});

it('rejects a forged corrective schedule type at the form level', function (): void {
    [$customer, $unit] = kindEquipment();

    Livewire::actingAs(kindManager())->test(CreateMaintenanceSchedule::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'name' => 'Forged',
            'interval_type' => MaintenanceIntervalType::Months->value,
            'interval_value' => 1,
            'lead_time_days' => 1,
            'first_due_on' => now()->addMonth()->toDateString(),
            'billing_type' => MaintenanceBillingType::Unbilled->value,
            'maintenance_kind' => MaintenanceKind::Corrective->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['maintenance_kind']);

    expect(MaintenanceSchedule::query()->count())->toBe(0);
});

it('hides calibration from the schedule type options while calibration is disabled', function (): void {
    config(['support.calibration_enabled' => false]);

    Livewire::actingAs(kindManager())->test(CreateMaintenanceSchedule::class)
        ->fillForm(['maintenance_kind' => MaintenanceKind::Calibration->value])
        ->call('create')
        ->assertHasFormErrors(['maintenance_kind']);
});

it('shows the schedule type in the list and on the schedule detail page', function (): void {
    $manager = kindManager();
    $schedule = kindSchedule(MaintenanceKind::Calibration, $manager);

    Livewire::actingAs($manager)->test(ListMaintenanceSchedules::class)
        ->assertCanSeeTableRecords([$schedule])
        ->assertSee('Calibration');

    Livewire::actingAs($manager)->test(ViewMaintenanceSchedule::class, ['record' => $schedule->getRouteKey()])
        ->assertSee('Schedule type')
        ->assertSee('Calibration');
});
