<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\ServiceAppointmentStatus;
use App\Filament\Resources\ServiceAppointments\Pages\ListServiceAppointments;
use App\Filament\Resources\ServiceAppointments\Tables\ServiceAppointmentsTable;
use App\Models\CustomerDeliveryAddress;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\User;
use App\Services\Support\ServiceAppointmentService;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function dispatchCoverageManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

function dispatchCoverageAppointment(User $manager, EmployeeProfile $employee, ?MaintenanceTask $task = null, int $dayOffset = 1): ServiceAppointment
{
    $start = now()->addDays($dayOffset)->startOfHour();

    return app(ServiceAppointmentService::class)->createScheduled(
        $task ?? MaintenanceTask::factory()->create(),
        $employee,
        $start,
        $start->copy()->addHours(2),
        null,
        $manager,
    );
}

it('reschedules a planned appointment to another technician and window and logs the change', function (): void {
    $manager = dispatchCoverageManager();
    $this->actingAs($manager);
    $original = EmployeeProfile::factory()->create();
    $replacement = EmployeeProfile::factory()->create();
    $appointment = dispatchCoverageAppointment($manager, $original);

    $task = $appointment->serviceRecord;
    $address = CustomerDeliveryAddress::factory()->create([
        'customer_profile_id' => $task->maintenanceRecord->customer->getKey(),
        'is_active' => true,
        'address' => '9 Workshop Road',
        'city' => 'Homs',
        'latitude' => 34.7324,
        'longitude' => 36.7137,
    ]);

    $start = now()->addDays(3)->startOfHour();
    $end = $start->copy()->addHours(3);

    $updated = app(ServiceAppointmentService::class)->reschedule(
        $appointment,
        $replacement,
        $start,
        $end,
        $address,
        $manager,
        'Customer asked for a later slot.',
    );

    expect($updated->employee_id)->toBe($replacement->getKey())
        ->and($updated->status)->toBe(ServiceAppointmentStatus::Planned)
        ->and($updated->scheduled_start_at->equalTo($start))->toBeTrue()
        ->and($updated->scheduled_end_at->equalTo($end))->toBeTrue()
        ->and($updated->estimated_duration_minutes)->toBe(180)
        ->and($updated->address_snapshot['source'])->toBe('delivery_address')
        ->and($updated->address_snapshot['city'])->toBe('Homs')
        ->and((float) $updated->latitude)->toBe(34.7324)
        ->and($updated->notes)->toBe('Customer asked for a later slot.')
        ->and($updated->updated_by)->toBe($manager->getKey());

    $log = Activity::query()
        ->where('description', 'support.service_appointment.rescheduled')
        ->where('subject_id', $appointment->getKey())
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($manager->getKey())
        ->and($log->attribute_changes['old']['employee_id'])->toBe($original->getKey())
        ->and($log->attribute_changes['attributes']['employee_id'])->toBe($replacement->getKey());
});

it('lets an appointment be rescheduled within its own current window without a self-conflict', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $appointment = dispatchCoverageAppointment($manager, $employee);

    $updated = app(ServiceAppointmentService::class)->reschedule(
        $appointment,
        $employee,
        $appointment->scheduled_start_at->copy()->addMinutes(30),
        $appointment->scheduled_end_at->copy()->addMinutes(30),
        null,
        $manager,
    );

    expect($updated->employee_id)->toBe($employee->getKey())
        ->and($updated->address_snapshot['source'])->toBe('customer_profile')
        ->and($updated->scheduled_start_at->equalTo($appointment->scheduled_start_at->copy()->addMinutes(30)))->toBeTrue();
});

it('rejects rescheduling that overlaps another appointment of the target technician', function (): void {
    $manager = dispatchCoverageManager();
    $busy = EmployeeProfile::factory()->create();
    $free = EmployeeProfile::factory()->create();
    $blocker = dispatchCoverageAppointment($manager, $busy, null, 2);
    $appointment = dispatchCoverageAppointment($manager, $free, null, 1);

    expect(fn () => app(ServiceAppointmentService::class)->reschedule(
        $appointment,
        $busy,
        $blocker->scheduled_start_at->copy()->addMinutes(30),
        $blocker->scheduled_end_at->copy()->addMinutes(30),
        null,
        $manager,
    ))->toThrow(ValidationException::class, 'overlapping');

    $appointment->refresh();

    expect($appointment->employee_id)->toBe($free->getKey())
        ->and($appointment->scheduled_start_at->day)->not->toBe($blocker->scheduled_start_at->copy()->addMinutes(30)->day);
});

it('rejects rescheduling to an inactive technician or with an inverted window', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $inactive = EmployeeProfile::factory()->create(['is_active' => false]);
    $appointment = dispatchCoverageAppointment($manager, $employee);
    $service = app(ServiceAppointmentService::class);
    $start = now()->addDays(4)->startOfHour();

    expect(fn () => $service->reschedule($appointment, $inactive, $start, $start->copy()->addHour(), null, $manager))
        ->toThrow(DomainException::class, 'Inactive employees')
        ->and(fn () => $service->reschedule($appointment, $employee, $start, $start->copy(), null, $manager))
        ->toThrow(ValidationException::class);

    expect($appointment->refresh()->employee_id)->toBe($employee->getKey());
});

it('refuses to reschedule completed or cancelled appointments', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $service = app(ServiceAppointmentService::class);

    $cancelled = dispatchCoverageAppointment($manager, $employee, null, 1);
    $service->cancel($cancelled, $manager);

    $completed = dispatchCoverageAppointment($manager, $employee, null, 2);
    $service->dispatch($completed, $manager);
    $service->checkIn($completed->refresh(), $manager);
    $service->complete($completed->refresh(), $manager, 'Customer');

    $start = now()->addDays(5)->startOfHour();

    foreach ([$cancelled, $completed] as $appointment) {
        expect(fn () => $service->reschedule($appointment->refresh(), $employee, $start, $start->copy()->addHour(), null, $manager))
            ->toThrow(DomainException::class, 'cannot be rescheduled');
    }

    expect($cancelled->refresh()->status)->toBe(ServiceAppointmentStatus::Cancelled)
        ->and($completed->refresh()->status)->toBe(ServiceAppointmentStatus::Completed);
});

it('refuses to schedule or reschedule against a closed or cancelled service record', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $service = app(ServiceAppointmentService::class);

    $task = MaintenanceTask::factory()->create();
    $appointment = dispatchCoverageAppointment($manager, $employee, $task);
    $start = now()->addDays(6)->startOfHour();

    foreach ([MaintenanceStatus::Closed, MaintenanceStatus::Cancelled] as $status) {
        $task->forceFill(['status' => $status])->save();

        expect(fn () => $service->createScheduled($task->refresh(), $employee, $start, $start->copy()->addHour(), null, $manager))
            ->toThrow(DomainException::class, 'closed or cancelled service record')
            ->and(fn () => $service->reschedule($appointment->refresh(), $employee, $start, $start->copy()->addHour(), null, $manager))
            ->toThrow(DomainException::class, 'closed or cancelled service record');
    }

    expect(ServiceAppointment::query()->where('maintenance_task_id', $task->getKey())->count())->toBe(1);
});

it('does not let a technician or a user without permission reschedule an appointment', function (): void {
    $manager = dispatchCoverageManager();
    $technicianUser = User::factory()->admin()->create();
    $technicianUser->assignRole('Support Agent');

    $technician = EmployeeProfile::factory()->create(['user_id' => $technicianUser->getKey()]);
    $appointment = dispatchCoverageAppointment($manager, $technician);
    $start = now()->addDays(7)->startOfHour();

    expect(fn () => app(ServiceAppointmentService::class)->reschedule(
        $appointment,
        $technician,
        $start,
        $start->copy()->addHour(),
        null,
        $technicianUser,
    ))->toThrow(AuthorizationException::class);

    expect($appointment->refresh()->scheduled_start_at->day)->not->toBe($start->day);
});

it('reschedules an appointment from the dispatch table and reports overlaps as a notification', function (): void {
    $manager = dispatchCoverageManager();
    $original = EmployeeProfile::factory()->create();
    $replacement = EmployeeProfile::factory()->create();
    $appointment = dispatchCoverageAppointment($manager, $original);

    $address = CustomerDeliveryAddress::factory()->create([
        'customer_profile_id' => $appointment->serviceRecord->maintenanceRecord->customer->getKey(),
        'is_active' => true,
        'address' => '77 Depot Street',
        'city' => 'Latakia',
    ]);

    $start = now()->addDays(3)->startOfHour();
    $end = $start->copy()->addHours(2);

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->assertTableActionVisible('reschedule', $appointment)
        ->callAction(TestAction::make('reschedule')->table($appointment), [
            'employee_id' => $replacement->getKey(),
            'customer_delivery_address_id' => $address->getKey(),
            'scheduled_start_at' => $start->toDateTimeString(),
            'scheduled_end_at' => $end->toDateTimeString(),
            'notes' => 'Moved by dispatch.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Appointment rescheduled');

    $appointment->refresh();

    expect($appointment->employee_id)->toBe($replacement->getKey())
        ->and($appointment->address_snapshot['address'])->toBe('77 Depot Street')
        ->and($appointment->notes)->toBe('Moved by dispatch.')
        ->and($appointment->scheduled_start_at->equalTo($start))->toBeTrue();

    $blocker = dispatchCoverageAppointment($manager, $original, null, 5);

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->callAction(TestAction::make('reschedule')->table($appointment), [
            'employee_id' => $original->getKey(),
            'scheduled_start_at' => $blocker->scheduled_start_at->toDateTimeString(),
            'scheduled_end_at' => $blocker->scheduled_end_at->toDateTimeString(),
            'notes' => null,
        ])
        ->assertNotified('Unable to reschedule appointment');

    expect($appointment->refresh()->employee_id)->toBe($replacement->getKey());
});

it('hides the reschedule action for completed appointments', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $appointment = dispatchCoverageAppointment($manager, $employee);
    $service = app(ServiceAppointmentService::class);
    $service->cancel($appointment, $manager);

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->assertTableActionHidden('reschedule', $appointment->refresh());
});

it('schedules an appointment from the dispatch board header action', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $task = MaintenanceTask::factory()->create(['title' => 'Replace grinder burrs']);
    $address = CustomerDeliveryAddress::factory()->create([
        'customer_profile_id' => $task->maintenanceRecord->customer->getKey(),
        'is_active' => true,
        'label' => 'Main workshop',
        'address' => '5 Market Street',
        'city' => 'Aleppo',
    ]);

    $start = now()->addDays(2)->startOfHour();
    $end = $start->copy()->addHours(2);

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->callAction('scheduleAppointment', [
            'maintenance_task_id' => $task->getKey(),
            'employee_id' => $employee->getKey(),
            'customer_delivery_address_id' => $address->getKey(),
            'scheduled_start_at' => $start->toDateTimeString(),
            'scheduled_end_at' => $end->toDateTimeString(),
            'notes' => 'Bring spare burrs.',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Appointment scheduled');

    $appointment = ServiceAppointment::query()->where('maintenance_task_id', $task->getKey())->firstOrFail();

    expect($appointment->status)->toBe(ServiceAppointmentStatus::Planned)
        ->and($appointment->employee_id)->toBe($employee->getKey())
        ->and($appointment->address_snapshot['label'])->toBe('Main workshop')
        ->and($appointment->notes)->toBe('Bring spare burrs.')
        ->and($appointment->created_by)->toBe($manager->getKey());
});

it('offers open service records, active technicians and active addresses in the schedule form', function (): void {
    $manager = dispatchCoverageManager();
    $technicianUser = User::factory()->create(['name' => 'Rima Technician']);
    EmployeeProfile::factory()->create(['user_id' => $technicianUser->getKey()]);
    EmployeeProfile::factory()->create(['is_active' => false]);

    $open = MaintenanceTask::factory()->create(['title' => 'Open service job']);
    $closed = MaintenanceTask::factory()->create(['title' => 'Closed service job', 'status' => MaintenanceStatus::Closed]);

    $active = CustomerDeliveryAddress::factory()->create(['is_active' => true, 'label' => 'Active depot', 'address' => '1 Active Way']);
    CustomerDeliveryAddress::factory()->create(['is_active' => false, 'label' => 'Retired depot', 'address' => '2 Retired Way']);

    $component = Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->mountAction('scheduleAppointment');

    $page = $component->instance();
    $schema = new ReflectionMethod($page, 'getMountedActionSchema')->invoke($page);
    $taskOptions = $schema->getComponent('maintenance_task_id', withHidden: true)->getOptions();
    $employeeOptions = $schema->getComponent('employee_id', withHidden: true)->getOptions();
    $addressOptions = $schema->getComponent('customer_delivery_address_id', withHidden: true)->getOptions();

    expect($taskOptions)->toHaveKey($open->getKey())
        ->and($taskOptions)->not->toHaveKey($closed->getKey())
        ->and($taskOptions[$open->getKey()])->toContain('Open service job')
        ->and($employeeOptions)->toHaveCount(1)
        ->and(array_values($employeeOptions))->toBe(['Rima Technician'])
        ->and($addressOptions)->toHaveCount(1)
        ->and($addressOptions[$active->getKey()])->toBe('Active depot — 1 Active Way');
});

it('reports failed scheduling attempts from the header action as a notification', function (): void {
    $manager = dispatchCoverageManager();
    $employee = EmployeeProfile::factory()->create();
    $existing = dispatchCoverageAppointment($manager, $employee);
    $task = MaintenanceTask::factory()->create();

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->callAction('scheduleAppointment', [
            'maintenance_task_id' => $task->getKey(),
            'employee_id' => $employee->getKey(),
            'scheduled_start_at' => $existing->scheduled_start_at->toDateTimeString(),
            'scheduled_end_at' => $existing->scheduled_end_at->toDateTimeString(),
            'notes' => null,
        ])
        ->assertNotified('Unable to schedule appointment');

    expect(ServiceAppointment::query()->where('maintenance_task_id', $task->getKey())->exists())->toBeFalse();
});

it('requires an authenticated user in the dispatch table and the dispatch page helpers', function (): void {
    expect(auth()->user())->toBeNull();

    $tableActor = new ReflectionMethod(ServiceAppointmentsTable::class, 'currentActor');

    expect(fn (): mixed => $tableActor->invoke(null))->toThrow(LogicException::class, 'An authenticated User is required.');

    $manager = dispatchCoverageManager();
    $page = Livewire::actingAs($manager)->test(ListServiceAppointments::class)->instance();
    $pageActor = new ReflectionMethod($page, 'currentActor');

    expect($pageActor->invoke($page)->is($manager))->toBeTrue();

    auth()->logout();

    expect(fn (): mixed => $pageActor->invoke($page))->toThrow(LogicException::class, 'An authenticated User is required.');
});
