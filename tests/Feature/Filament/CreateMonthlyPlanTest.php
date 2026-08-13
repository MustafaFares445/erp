<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\CreateMonthlyPlan;
use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\SalesPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('creates a monthly plan through the create form', function (): void {
    $admin = User::factory()->admin()->create();
    $employee = EmployeeProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test(CreateMonthlyPlan::class)
        ->fillForm([
            'employee_id' => $employee->id,
            'name' => 'Riyadh territory plan',
            'month' => now()->startOfMonth()->toDateString(),
            'task_weight' => 40,
            'visit_weight' => 30,
            'schedule_weight' => 20,
            'work_time_weight' => 10,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $plan = SalesPlan::query()->where('name', 'Riyadh territory plan')->firstOrFail();

    expect($plan->employee_id)->toBe($employee->id)
        ->and($plan->status->value)->toBe('Draft');
});

it('shows a notification instead of crashing when the create-time domain rule is violated', function (): void {
    $admin = User::factory()->admin()->create();
    $employee = EmployeeProfile::factory()->create();

    // SalesPlanService::create() never actually validates or throws
    // DomainException itself today, so the catch block in
    // CreateMonthlyPlan::handleRecordCreation() is purely defensive. Swapping
    // the service binding for a fake that throws is the only way to exercise
    // that branch without weakening the real service's behavior.
    app()->bind(SalesPlanService::class, fn (): object => new class
    {
        public function create(array $data): never
        {
            throw new DomainException('Simulated domain violation');
        }
    });

    Livewire::actingAs($admin)
        ->test(CreateMonthlyPlan::class)
        ->fillForm([
            'employee_id' => $employee->id,
            'name' => 'Doomed plan',
            'month' => now()->startOfMonth()->toDateString(),
            'task_weight' => 40,
            'visit_weight' => 30,
            'schedule_weight' => 20,
            'work_time_weight' => 10,
        ])
        ->call('create')
        ->assertNotified();

    expect(SalesPlan::query()->where('name', 'Doomed plan')->exists())->toBeFalse();
});
