<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Filament\Resources\DashboardUsers\DashboardUserResource;
use App\Filament\Resources\DashboardUsers\Pages\EditDashboardUser;
use App\Filament\Resources\DashboardUsers\Pages\ListDashboardUsers;
use App\Models\AuditLog;
use App\Models\PricingTier;
use App\Models\User;
use App\Services\Identity\DashboardRoleAssignmentService;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('assigns exactly one fixed dashboard role to dashboard-channel users', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('System Admin');

    $target = User::factory()->admin()->create();

    Livewire::actingAs($actor)
        ->test(EditDashboardUser::class, ['record' => $target->id])
        ->fillForm(['role_name' => 'Reviewer'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->refresh()->getRoleNames()->all())->toBe(['Reviewer'])
        ->and(AuditLog::query()->where('action', 'identity.dashboard_roles.assigned')->exists())->toBeTrue();
});

it('does not expose customer-channel accounts or role assignment to non-administrators', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $customer = User::factory()->customer()->create();

    $this->actingAs($reviewer)
        ->get(DashboardUserResource::getUrl())
        ->assertForbidden();

    expect(DashboardUserResource::getEloquentQuery()->whereKey($customer->id)->exists())->toBeFalse();
});

it('rejects invalid dashboard role assignments and supports the bare administrator fallback', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();
    $service = app(DashboardRoleAssignmentService::class);

    expect(fn (): User => $service->assign($target, 'Custom role', $actor))
        ->toThrow(DomainException::class, 'fixed')
        ->and(fn (): User => $service->assign(User::factory()->create(['user_type' => UserType::Employee]), 'Reviewer', $actor))
        ->toThrow(DomainException::class, 'dashboard-channel');

    Role::query()->where('name', 'Reviewer')->delete();

    expect(fn (): User => $service->assign($target, 'Reviewer', $actor))
        ->toThrow(DomainException::class, 'not available');
});

it('configures dashboard-user navigation table and immutable resource operations', function (): void {
    (new CrmPermissionSeeder)->run();
    $actor = User::factory()->admin()->create();
    $actor->assignRole('System Admin');

    $target = User::factory()->admin()->create();

    Livewire::actingAs($actor)
        ->test(ListDashboardUsers::class)
        ->assertCanSeeTableRecords([$actor, $target]);

    expect(DashboardUserResource::getNavigationLabel())->toBe(__('admin.resources.dashboard_users'))
        ->and(DashboardUserResource::canViewAny())->toBeTrue()
        ->and(DashboardUserResource::canCreate())->toBeFalse()
        ->and(DashboardUserResource::canDeleteAny())->toBeFalse();
});

it('fails early when the dashboard-role edit handler receives invalid context', function (): void {
    $page = new EditDashboardUser;
    $method = new ReflectionMethod($page, 'handleRecordUpdate');

    expect(fn (): mixed => $method->invoke($page, new PricingTier, ['role_name' => 'Reviewer']))
        ->toThrow(LogicException::class, 'dashboard user and fixed role');

    auth()->logout();

    expect(fn (): mixed => $method->invoke($page, new User, ['role_name' => 'Reviewer']))
        ->toThrow(LogicException::class, 'authenticated dashboard role administrator');
});
