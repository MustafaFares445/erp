<?php

declare(strict_types=1);

use App\Filament\Resources\MonthlyPlans\Pages\EditMonthlyPlan;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Employees\SalesPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('updates a monthly plan through the edit form', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['name' => 'Old plan name']);

    Livewire::actingAs($admin)
        ->test(EditMonthlyPlan::class, ['record' => $plan->getKey()])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'New plan name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->fresh()->name)->toBe('New plan name');
});

it('shows a notification instead of crashing when the save-time domain rule is violated', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create(['name' => 'Old plan name']);

    // SalesPlanService::update() never actually validates or throws
    // DomainException itself today, so the catch block in
    // EditMonthlyPlan::handleRecordUpdate() is purely defensive. Swapping
    // the service binding for a fake that throws is the only way to
    // exercise that branch without weakening the real service's behavior.
    app()->bind(SalesPlanService::class, fn (): object => new class
    {
        public function update(SalesPlan $plan, array $data): never
        {
            throw new DomainException('Simulated domain violation');
        }
    });

    Livewire::actingAs($admin)
        ->test(EditMonthlyPlan::class, ['record' => $plan->getKey()])
        ->fillForm([
            'name' => 'Should not persist',
        ])
        ->call('save')
        ->assertNotified();

    expect($plan->fresh()->name)->toBe('Old plan name');
});

it('rejects a non-plan record in the edit handler', function (): void {
    $page = new EditMonthlyPlan;
    $method = new ReflectionMethod(EditMonthlyPlan::class, 'handleRecordUpdate');

    expect(fn (): mixed => $method->invoke($page, new User, []))
        ->toThrow(LogicException::class);
});
