<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\EmployeeProfile;
use App\Services\Employees\EmployeeOnboardingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates the user and employee profile pair with a unique code', function (): void {
    $profile = app(EmployeeOnboardingService::class)->onboard([
        'name' => 'Sara Ahmed',
        'login_email' => 'sara.ahmed@example.com',
        'job_title' => 'Field Sales Representative',
        'phone' => '+971500000000',
        'use_base_salary' => true,
        'base_salary' => 8000,
    ]);

    expect($profile->employee_code)->toStartWith('EMP-')
        ->and($profile->user->user_type)->toBe(UserType::Employee)
        ->and($profile->is_active)->toBeTrue();
});

it('generates unique employee codes across multiple onboardings', function (): void {
    app(EmployeeOnboardingService::class)->onboard([
        'name' => 'First Employee',
        'login_email' => 'first.employee@example.com',
        'job_title' => 'Field Sales Representative',
        'use_base_salary' => true,
        'base_salary' => 5000,
    ]);
    app(EmployeeOnboardingService::class)->onboard([
        'name' => 'Second Employee',
        'login_email' => 'second.employee@example.com',
        'job_title' => 'Field Sales Representative',
        'use_base_salary' => true,
        'base_salary' => 5000,
    ]);

    $codes = EmployeeProfile::query()->pluck('employee_code');

    expect($codes)->toHaveCount(2)
        ->and($codes->unique())->toHaveCount(2);
});

it('rejects a duplicate employee_code even against an archived employee, at the database level', function (): void {
    EmployeeProfile::factory()->archived()->create(['employee_code' => 'EMP-9999']);

    expect(fn () => EmployeeProfile::factory()->create(['employee_code' => 'EMP-9999']))
        ->toThrow(QueryException::class);
});

it('sets the payable base to the commission target when base salary is disabled', function (): void {
    $profile = app(EmployeeOnboardingService::class)->onboard([
        'name' => 'Omar Youssef',
        'login_email' => 'omar.youssef@example.com',
        'job_title' => 'Account Manager',
        'use_base_salary' => false,
        'commission_target_amount' => 4000,
    ]);

    expect($profile->use_base_salary)->toBeFalse()
        ->and((float) $profile->commission_target_amount)->toBe(4000.0)
        ->and($profile->base_salary)->toBeNull();
});
