<?php

declare(strict_types=1);

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('searches employees by code or name', function (): void {
    $admin = User::factory()->admin()->create();
    $matching = EmployeeProfile::factory()->create(['employee_code' => 'EMP-SEARCH']);
    EmployeeProfile::factory()->create(['employee_code' => 'EMP-OTHER']);

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->searchTable('EMP-SEARCH')
        ->assertCanSeeTableRecords([$matching])
        ->searchTable($matching->job_title)
        ->assertCanSeeTableRecords([$matching]);
});

it('filters employees by active status and job title', function (): void {
    $admin = User::factory()->admin()->create();
    $active = EmployeeProfile::factory()->create(['is_active' => true, 'job_title' => 'Field Sales Representative']);
    $inactive = EmployeeProfile::factory()->inactive()->create(['job_title' => 'Warehouse Coordinator']);

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->filterTable('is_active', true)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$inactive]);
});

it('paginates the employee list', function (): void {
    $admin = User::factory()->admin()->create();
    EmployeeProfile::factory()->count(15)->create();

    Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->assertCountTableRecords(15);
});
