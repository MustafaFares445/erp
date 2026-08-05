<?php

declare(strict_types=1);

use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\Employees\EmployeeAccessService;
use App\Services\Employees\EmployeeOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('writes a retrievable audit entry with actor and timestamp for every sensitive employee action', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $profile = app(EmployeeOnboardingService::class)->onboard([
        'name' => 'Layla Hassan',
        'login_email' => 'layla.hassan@example.com',
        'job_title' => 'Field Sales Representative',
        'use_base_salary' => true,
        'base_salary' => 6000,
    ]);

    $created = AuditLog::query()->where('action', 'employee.created')->where('entity_id', $profile->id)->sole();
    expect($created->actor_user_id)->toBe($actor->id)
        ->and($created->created_at)->not->toBeNull();

    $service = app(EmployeeAccessService::class);

    $service->disable($profile);
    $disabled = AuditLog::query()->where('action', 'employee.access_disabled')->where('entity_id', $profile->id)->sole();
    expect($disabled->actor_user_id)->toBe($actor->id);

    $service->enable($profile);
    $enabled = AuditLog::query()->where('action', 'employee.access_enabled')->where('entity_id', $profile->id)->sole();
    expect($enabled->actor_user_id)->toBe($actor->id);

    $service->archive($profile);
    $archived = AuditLog::query()->where('action', 'employee.archived')->where('entity_id', $profile->id)->sole();
    expect($archived->actor_user_id)->toBe($actor->id);

    $service->restore(EmployeeProfile::withTrashed()->findOrFail($profile->id));
    $restored = AuditLog::query()->where('action', 'employee.restored')->where('entity_id', $profile->id)->sole();
    expect($restored->actor_user_id)->toBe($actor->id);
});

it('routes the dashboard delete/restore/access table actions through the audited service, not a direct model write', function (): void {
    $actor = User::factory()->admin()->create();
    $profile = EmployeeProfile::factory()->create(['is_active' => true]);

    Livewire::actingAs($actor)
        ->test(ListEmployees::class)
        ->callTableAction('disable', $profile);

    expect($profile->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'employee.access_disabled')->where('entity_id', $profile->id)->exists())->toBeTrue();

    Livewire::actingAs($actor)
        ->test(ListEmployees::class)
        ->callTableAction('archive', $profile);

    expect(EmployeeProfile::query()->find($profile->id))->toBeNull()
        ->and(AuditLog::query()->where('action', 'employee.archived')->where('entity_id', $profile->id)->exists())->toBeTrue();
});
