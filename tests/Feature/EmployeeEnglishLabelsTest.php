<?php

declare(strict_types=1);

use App\Filament\Resources\EmployeeReports\EmployeeReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\MonthlyPlans\MonthlyPlanResource;
use App\Filament\Resources\Performance\PerformanceResource;
use App\Filament\Resources\SalaryCalculations\SalaryCalculationResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders English labels on every employees dashboard surface, with no untranslated key leaking through', function (): void {
    (new EmployeePermissionSeeder)->run();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    app()->setLocale('en');

    $resources = [
        EmployeeResource::class,
        MonthlyPlanResource::class,
        TaskResource::class,
        VisitResource::class,
        PerformanceResource::class,
        SalaryCalculationResource::class,
        EmployeeReportResource::class,
    ];

    foreach ($resources as $resource) {
        $response = $this->actingAs($admin)->get($resource::getUrl());

        $response->assertOk()
            ->assertDontSee('admin.resources.')
            ->assertDontSee('admin.sections.')
            ->assertDontSee('admin.groups.')
            ->assertDontSee('admin.employees.');
    }
});

it('shows the correct Arabic navigation label for every employees dashboard item', function (): void {
    (new EmployeePermissionSeeder)->run();
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->assignRole('System Admin');

    $labels = [
        'employees',
        'monthly_plans',
        'tasks',
        'visits',
        'performance',
        'salary_calculations',
    ];

    $response = $this->actingAs($admin)->get(EmployeeResource::getUrl());

    foreach ($labels as $label) {
        $response->assertSee(__('admin.resources.'.$label, [], 'ar'));
    }
});
