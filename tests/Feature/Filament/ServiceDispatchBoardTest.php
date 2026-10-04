<?php

declare(strict_types=1);

use App\Filament\Resources\ServiceAppointments\Pages\ListServiceAppointments;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the field-service dispatch list for support managers', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $employee = EmployeeProfile::factory()->create();
    $task = MaintenanceTask::factory()->create();

    $appointment = ServiceAppointment::query()->create([
        'maintenance_task_id' => $task->id,
        'employee_id' => $employee->id,
        'status' => 'planned',
        'scheduled_start_at' => now()->addDay(),
        'scheduled_end_at' => now()->addDay()->addHour(),
        'estimated_duration_minutes' => 60,
        'address_snapshot' => ['address' => 'Test address', 'city' => 'Aleppo'],
    ]);

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$appointment])
        ->assertTableActionExists('dispatch', null, $appointment)
        ->assertActionExists('scheduleAppointment');
});

it('keeps soft-deleted visits off the field-service dispatch board', function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $employee = EmployeeProfile::factory()->create();

    $visible = ServiceAppointment::query()->create([
        'maintenance_task_id' => MaintenanceTask::factory()->create()->id,
        'employee_id' => $employee->id,
        'status' => 'planned',
        'scheduled_start_at' => now()->addDay(),
        'scheduled_end_at' => now()->addDay()->addHour(),
        'estimated_duration_minutes' => 60,
        'address_snapshot' => ['address' => 'Test address', 'city' => 'Aleppo'],
    ]);
    $removed = ServiceAppointment::query()->create([
        'maintenance_task_id' => MaintenanceTask::factory()->create()->id,
        'employee_id' => $employee->id,
        'status' => 'planned',
        'scheduled_start_at' => now()->addDays(2),
        'scheduled_end_at' => now()->addDays(2)->addHour(),
        'estimated_duration_minutes' => 60,
        'address_snapshot' => ['address' => 'Test address', 'city' => 'Aleppo'],
    ]);
    $removed->delete();

    Livewire::actingAs($manager)
        ->test(ListServiceAppointments::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$removed]);
});
