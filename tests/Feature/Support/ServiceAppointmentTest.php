<?php

declare(strict_types=1);

use App\Enums\ServiceAppointmentStatus;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\CustomerDeliveryAddress;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\ServiceAppointmentService;
use App\Services\Support\SlaService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function fieldServiceManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('schedules, dispatches, checks in and completes an on-site appointment', function (): void {
    config()->set('support.sla_v2_enabled', true);

    $manager = fieldServiceManager();
    $employee = EmployeeProfile::factory()->create();

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'service_path' => TicketServicePath::OnSiteVisit,
    ]);
    app(SlaService::class)->onTicketCreated($ticket);
    app(SlaService::class)->onTicketLive($ticket->refresh());

    $maintenance = MaintenanceRecord::factory()->fromTicket()->create([
        'ticket_id' => $ticket->id,
        'customer_id' => $ticket->customer_id,
    ]);
    $task = MaintenanceTask::factory()->create([
        'maintenance_record_id' => $maintenance->id,
        'employee_id' => $employee->id,
    ]);

    $customer = $maintenance->customer;
    $address = CustomerDeliveryAddress::factory()->create([
        'customer_profile_id' => $customer->id,
        'is_active' => true,
        'address' => '123 Service Street',
        'city' => 'Aleppo',
        'latitude' => 36.2021,
        'longitude' => 37.1343,
    ]);

    $start = now()->addDay()->startOfHour();
    $end = $start->copy()->addHours(2);

    $service = app(ServiceAppointmentService::class);
    $appointment = $service->createScheduled($task, $employee, $start, $end, $address, $manager);

    expect($appointment->status)->toBe(ServiceAppointmentStatus::Planned)
        ->and($appointment->address_snapshot['source'])->toBe('delivery_address')
        ->and($appointment->estimated_duration_minutes)->toBe(120);

    $service->dispatch($appointment, $manager);
    $service->markEnRoute($appointment->refresh(), $manager);
    $service->checkIn($appointment->refresh(), $manager, 36.2022, 37.1344);

    expect($appointment->refresh()->status)->toBe(ServiceAppointmentStatus::OnSite)
        ->and($appointment->checked_in_at)->not->toBeNull();

    $service->complete($appointment->refresh(), $manager, 'Customer Name', 36.2023, 37.1345, 'Visit complete.');

    expect($appointment->refresh()->status)->toBe(ServiceAppointmentStatus::Completed)
        ->and($appointment->customer_signature_name)->toBe('Customer Name')
        ->and($appointment->checked_out_at)->not->toBeNull();
});

it('rejects overlapping appointments for the same technician', function (): void {
    $manager = fieldServiceManager();
    $employee = EmployeeProfile::factory()->create();
    $task = MaintenanceTask::factory()->create();

    $start = now()->addDay()->startOfHour();
    $end = $start->copy()->addHours(2);
    $service = app(ServiceAppointmentService::class);

    $service->createScheduled($task, $employee, $start, $end, null, $manager);

    expect(fn () => $service->createScheduled(
        $task,
        $employee,
        $start->copy()->addHour(),
        $end->copy()->addHour(),
        null,
        $manager,
    ))->toThrow(ValidationException::class);
});

it('rejects a delivery address that belongs to another customer', function (): void {
    $manager = fieldServiceManager();
    $employee = EmployeeProfile::factory()->create();
    $task = MaintenanceTask::factory()->create();
    $foreignAddress = CustomerDeliveryAddress::factory()->create(['is_active' => true]);

    expect(fn () => app(ServiceAppointmentService::class)->createScheduled(
        $task,
        $employee,
        now()->addDay(),
        now()->addDay()->addHour(),
        $foreignAddress,
        $manager,
    ))->toThrow(DomainException::class);
});

it('requires customer sign-off before completing an on-site visit', function (): void {
    $manager = fieldServiceManager();
    $employee = EmployeeProfile::factory()->create();
    $task = MaintenanceTask::factory()->create();
    $service = app(ServiceAppointmentService::class);

    $appointment = $service->createScheduled(
        $task,
        $employee,
        now()->addDay(),
        now()->addDay()->addHour(),
        null,
        $manager,
    );
    $service->dispatch($appointment, $manager);
    $service->checkIn($appointment->refresh(), $manager);

    expect(fn () => $service->complete($appointment->refresh(), $manager, ''))
        ->toThrow(ValidationException::class);
});

function scheduledAppointment(User $manager, EmployeeProfile $employee): ServiceAppointment
{
    return app(ServiceAppointmentService::class)->createScheduled(
        MaintenanceTask::factory()->create(),
        $employee,
        now()->addDay()->startOfHour(),
        now()->addDay()->startOfHour()->addHours(2),
        null,
        $manager,
    );
}

it('lets the assigned technician execute a visit but not dispatch or cancel it', function (): void {
    $manager = fieldServiceManager();
    $technicianUser = User::factory()->admin()->create();
    $technicianUser->assignRole('Support Agent');

    $technician = EmployeeProfile::factory()->create(['user_id' => $technicianUser->getKey()]);
    $service = app(ServiceAppointmentService::class);

    $appointment = scheduledAppointment($manager, $technician);

    expect(fn () => $service->dispatch($appointment, $technicianUser))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->cancel($appointment, $technicianUser))->toThrow(AuthorizationException::class);

    $service->dispatch($appointment, $manager);
    $service->markEnRoute($appointment->refresh(), $technicianUser);
    $service->checkIn($appointment->refresh(), $technicianUser, 25.2, 55.3);

    expect($appointment->refresh()->status)->toBe(ServiceAppointmentStatus::OnSite)
        ->and((float) $appointment->check_in_latitude)->toBe(25.2)
        ->and((float) $appointment->check_in_longitude)->toBe(55.3);
});

it("does not let a technician execute someone else's visit", function (): void {
    $manager = fieldServiceManager();
    $technicianUser = User::factory()->admin()->create();
    $technicianUser->assignRole('Support Agent');
    EmployeeProfile::factory()->create(['user_id' => $technicianUser->getKey()]);
    $service = app(ServiceAppointmentService::class);

    $appointment = scheduledAppointment($manager, EmployeeProfile::factory()->create());
    $service->dispatch($appointment, $manager);

    expect(fn () => $service->markEnRoute($appointment->refresh(), $technicianUser))->toThrow(AuthorizationException::class);
});

it('rejects scheduling for an inactive technician and illegal status jumps', function (): void {
    $manager = fieldServiceManager();
    $service = app(ServiceAppointmentService::class);

    expect(fn () => $service->createScheduled(
        MaintenanceTask::factory()->create(),
        EmployeeProfile::factory()->create(['is_active' => false]),
        now()->addDay(),
        now()->addDay()->addHour(),
        null,
        $manager,
    ))->toThrow(DomainException::class);

    $appointment = scheduledAppointment($manager, EmployeeProfile::factory()->create());

    expect(fn () => $service->checkIn($appointment, $manager))->toThrow(DomainException::class);
});

it('cancels a planned visit, frees the technician slot and refuses to cancel a completed one', function (): void {
    $manager = fieldServiceManager();
    $employee = EmployeeProfile::factory()->create();
    $service = app(ServiceAppointmentService::class);

    $first = scheduledAppointment($manager, $employee);
    $service->cancel($first, $manager);

    expect($first->refresh()->status)->toBe(ServiceAppointmentStatus::Cancelled);

    // The same slot is free again for the technician.
    $second = scheduledAppointment($manager, $employee);
    $service->dispatch($second, $manager);
    $service->checkIn($second->refresh(), $manager);
    $service->complete($second->refresh(), $manager, 'Customer');

    expect(fn () => $service->cancel($second->refresh(), $manager))->toThrow(DomainException::class);
});
