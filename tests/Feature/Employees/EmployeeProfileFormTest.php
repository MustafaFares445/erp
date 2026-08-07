<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\EmployeeProfile;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('creates the user and employee profile pair through the create form', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    Livewire::actingAs($admin)
        ->test(CreateEmployee::class)
        ->fillForm([
            'name' => 'Sara Khalid',
            'username' => 'sara.khalid',
            'login_email' => 'sara.khalid@example.com',
            'job_title' => 'Field Sales Representative',
            'phone' => '+971501234567',
            'use_base_salary' => true,
            'base_salary' => 8500,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'sara.khalid@example.com')->firstOrFail();
    $profile = EmployeeProfile::query()->where('user_id', $user->id)->firstOrFail();

    expect($user->name)->toBe('Sara Khalid')
        ->and($user->username)->toBe('sara.khalid')
        ->and($user->user_type)->toBe(UserType::Employee)
        ->and($profile->employee_code)->toStartWith('EMP-')
        ->and($profile->job_title)->toBe('Field Sales Representative')
        ->and($profile->phone)->toBe('+971501234567')
        ->and($profile->is_active)->toBeTrue()
        ->and($profile->use_base_salary)->toBeTrue()
        ->and((float) $profile->base_salary)->toBe(8500.0);
});

it('shows a notification instead of crashing when the create-time domain rule is violated', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $component = Livewire::actingAs($admin)->test(CreateEmployee::class);

    // Filament's own form mirrors the model guard 1:1 (base_salary is
    // required(fn) exactly when use_base_salary is true), so a legitimate
    // fillForm()->call('create') can never carry an inconsistent payload
    // past form validation to reach handleRecordCreation()'s catch block.
    // Invoking the (still fully mounted) page method directly is the only
    // way to exercise that defensive branch.
    $handleRecordCreation = new ReflectionMethod($component->instance(), 'handleRecordCreation');

    $badData = [
        'name' => 'Bad Salary Employee',
        'login_email' => 'bad.salary@example.com',
        'job_title' => 'Field Sales Representative',
        'use_base_salary' => true,
        'base_salary' => null,
    ];

    expect(fn (): mixed => $handleRecordCreation->invoke($component->instance(), $badData))
        ->toThrow(Halt::class);

    $component->assertNotified();

    expect(User::query()->where('email', 'bad.salary@example.com')->exists())->toBeFalse();
});

it('renders the employee infolist with the record data', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create([
        'job_title' => 'Territory Manager',
        'phone' => '+971509999999',
    ]);

    Livewire::actingAs($admin)
        ->test(ViewEmployee::class, ['record' => $employee->getKey()])
        ->assertOk()
        ->assertSee($employee->employee_code)
        ->assertSee('Territory Manager')
        ->assertSee($employee->user->name);
});

it('updates an employee through the edit form', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create([
        'job_title' => 'Old Title',
        'is_active' => true,
    ]);

    Livewire::actingAs($admin)
        ->test(EditEmployee::class, ['record' => $employee->getKey()])
        ->fillForm([
            'job_title' => 'New Title',
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($employee->fresh())
        ->job_title->toBe('New Title')
        ->is_active->toBeFalse();
});

it('shows a notification instead of crashing when the save-time domain rule is violated', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $employee = EmployeeProfile::factory()->create([
        'use_base_salary' => true,
        'base_salary' => 6000,
        'commission_target_amount' => null,
    ]);

    $component = Livewire::actingAs($admin)->test(EditEmployee::class, ['record' => $employee->getKey()]);

    // Same reasoning as the create-time test above: commission_target_amount
    // is required(fn) exactly when use_base_salary is false, so the form
    // itself blocks any state that would violate the model guard. Reaching
    // handleRecordUpdate()'s catch block requires calling it directly.
    $handleRecordUpdate = new ReflectionMethod($component->instance(), 'handleRecordUpdate');

    $badData = [
        'job_title' => $employee->job_title,
        'is_active' => true,
        'use_base_salary' => false,
        'base_salary' => null,
        'commission_target_amount' => null,
    ];

    expect(fn (): mixed => $handleRecordUpdate->invoke($component->instance(), $employee, $badData))
        ->toThrow(Halt::class);

    $component->assertNotified();

    expect($employee->fresh())
        ->use_base_salary->toBeTrue()
        ->commission_target_amount->toBeNull();
});
