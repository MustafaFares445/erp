<?php

declare(strict_types=1);

use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires employee, plan task, customer, and outcome when field-editing a visit', function (): void {
    $admin = User::factory()->admin()->create();
    $visit = CustomerVisit::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditVisit::class, ['record' => $visit->getKey()])
        ->fillForm(['employee_id' => null, 'plan_task_id' => null, 'customer_id' => null, 'outcome' => null])
        ->call('save')
        ->assertHasFormErrors(['employee_id', 'plan_task_id', 'customer_id', 'outcome']);
});

it('saves a field-edited visit once employee, plan task, customer, and outcome are filled in', function (): void {
    $admin = User::factory()->admin()->create();
    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);
    $task = PlanTask::factory()->create(['sales_plan_id' => $plan->id]);
    $customer = CustomerProfile::factory()->create();
    $visit = CustomerVisit::factory()->create();

    Livewire::actingAs($admin)
        ->test(EditVisit::class, ['record' => $visit->getKey()])
        ->fillForm([
            'employee_id' => $employee->id,
            'plan_task_id' => $task->id,
            'customer_id' => $customer->id,
            'outcome' => 'Discussed renewal terms.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($visit->refresh())
        ->employee_id->toBe($employee->id)
        ->plan_task_id->toBe($task->id)
        ->customer_id->toBe($customer->id)
        ->outcome->toBe('Discussed renewal terms.');
});
