<?php

declare(strict_types=1);

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\EmployeeProfile;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('enables app access for an employee via the row action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create(['is_active' => false]);

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->callTableAction('enable', $employee);

    expect($employee->fresh()->is_active)->toBeTrue();
});

it('restores a single archived employee via the row action', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create();
    $employee->delete();

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->filterTable('trashed', true)
        ->callTableAction('restore', $employee);

    expect($employee->fresh()->trashed())->toBeFalse();
});

it('archives every selected employee when the bulk delete action runs', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $first = EmployeeProfile::factory()->create();
    $second = EmployeeProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->callTableBulkAction('archive', [$first, $second]);

    expect($first->fresh()->trashed())->toBeTrue()
        ->and($second->fresh()->trashed())->toBeTrue();
});

it('restores every selected employee when the bulk restore action runs', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $first = EmployeeProfile::factory()->create();
    $second = EmployeeProfile::factory()->create();
    $first->delete();
    $second->delete();

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->filterTable('trashed', true)
        ->callTableBulkAction('restore', [$first, $second]);

    expect($first->fresh()->trashed())->toBeFalse()
        ->and($second->fresh()->trashed())->toBeFalse();
});

it('shows both active and archived employees once the trashed filter is applied', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $active = EmployeeProfile::factory()->create();
    $archived = EmployeeProfile::factory()->create();
    $archived->delete();

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$active->fresh(), $archived->fresh()]);
});

it('shows only archived employees under the Archived tab', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $active = EmployeeProfile::factory()->create();
    $archived = EmployeeProfile::factory()->create();
    $archived->delete();

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->set('activeTab', 'archived')
        ->assertCanSeeTableRecords([$archived->fresh()])
        ->assertCanNotSeeTableRecords([$active]);
});
