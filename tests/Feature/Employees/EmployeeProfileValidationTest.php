<?php

declare(strict_types=1);

use App\Models\BonusSuggestion;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\SalesPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('relates to its sales plans, visits, and bonus suggestions', function (): void {
    $profile = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $profile->id]);
    $visit = CustomerVisit::factory()->for($profile, 'employee')->create();
    $bonus = BonusSuggestion::factory()->for($profile, 'employee')->create();

    expect($profile->salesPlans->pluck('id')->all())->toBe([$plan->id])
        ->and($profile->visits->pluck('id')->all())->toBe([$visit->id])
        ->and($profile->bonusSuggestions->pluck('id')->all())->toBe([$bonus->id]);
});

it('rejects a null base_salary when use_base_salary is true, naming the missing field', function (): void {
    expect(fn () => EmployeeProfile::factory()->make([
        'use_base_salary' => true,
        'base_salary' => null,
        'commission_target_amount' => null,
    ])->save())
        ->toThrow(DomainException::class, __('admin.employees.errors.missing_base_salary'));
});

it('rejects a null commission_target_amount when use_base_salary is false, naming the missing field', function (): void {
    expect(fn () => EmployeeProfile::factory()->make([
        'use_base_salary' => false,
        'base_salary' => null,
        'commission_target_amount' => null,
    ])->save())
        ->toThrow(DomainException::class, __('admin.employees.errors.missing_commission_target'));
});

it('never treats a missing payable base as a silent zero', function (): void {
    $profile = EmployeeProfile::factory()->baseSalary()->create();

    expect(fn () => $profile->update(['use_base_salary' => false, 'commission_target_amount' => null]))
        ->toThrow(DomainException::class);

    expect($profile->fresh()->use_base_salary)->toBeTrue();
});

it('accepts a profile with the commission target set when base salary is disabled', function (): void {
    $profile = EmployeeProfile::factory()->performanceOnly()->create();

    expect($profile->use_base_salary)->toBeFalse()
        ->and($profile->commission_target_amount)->not->toBeNull()
        ->and($profile->base_salary)->toBeNull();
});
