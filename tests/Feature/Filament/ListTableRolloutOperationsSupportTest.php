<?php

declare(strict_types=1);

use App\Enums\InventoryCountStatus;
use App\Enums\InventoryPermission;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\InventoryCounts\Pages\ListInventoryCounts;
use App\Filament\Resources\MaintenanceSchedules\Pages\ListMaintenanceSchedules;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Models\EmployeeProfile;
use App\Models\InventoryCount;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function rolloutSupportManager(): User
{
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

/**
 * @param  array<string, mixed>  $settings
 * @return array{rules: array<string, array{type: string, data: array{operator: string, settings: array<string, mixed>}}>}
 */
function operationsRolloutRule(string $type, string $operator, array $settings): array
{
    return ['rules' => ['rule' => ['type' => $type, 'data' => ['operator' => $operator, 'settings' => $settings]]]];
}

it('stars, filters and groups inventory counts', function (string $group): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::CountView->value);

    $draft = InventoryCount::factory()->create();
    $confirmed = InventoryCount::factory()->create(['status' => InventoryCountStatus::Confirmed]);

    Livewire::actingAs($user)
        ->test(ListInventoryCounts::class)
        ->assertSee(__('Starred'))
        ->assertCanSeeTableRecords([$draft, $confirmed])
        ->callTableColumnAction('is_favorited', $confirmed)
        ->call('selectTableView', 'preset', 'starred')
        ->assertCanSeeTableRecords([$confirmed])
        ->assertCanNotSeeTableRecords([$draft])
        ->call('selectTableView', 'preset', 'all')
        ->filterTable('queryBuilder', operationsRolloutRule('status', 'is', ['values' => [InventoryCountStatus::Draft->value]]))
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$confirmed])
        ->set('tableGrouping', $group)
        ->assertSuccessful();

    expect($confirmed->isFavoritedBy($user))->toBeTrue();
})->with(['status', 'warehouse.name', 'scope_type', 'opened_at']);

it('stars, filters and groups maintenance schedules', function (string $group): void {
    $manager = rolloutSupportManager();
    $unbilled = MaintenanceSchedule::factory()->create();
    $warranty = MaintenanceSchedule::factory()->create(['billing_type' => MaintenanceBillingType::WarrantyCovered]);

    Livewire::actingAs($manager)
        ->test(ListMaintenanceSchedules::class)
        ->assertSee(__('Starred'))
        ->callTableColumnAction('is_favorited', $warranty)
        ->call('selectTableView', 'preset', 'starred')
        ->assertCanSeeTableRecords([$warranty])
        ->assertCanNotSeeTableRecords([$unbilled])
        ->call('selectTableView', 'preset', 'all')
        ->filterTable('queryBuilder', operationsRolloutRule('serializedInventoryUnit', 'isRelatedTo', ['value' => [$unbilled->serialized_inventory_unit_id]]))
        ->assertCanSeeTableRecords([$unbilled])
        ->assertCanNotSeeTableRecords([$warranty])
        ->set('tableGrouping', $group)
        ->assertSuccessful();
})->with(['customer.company_name', 'billing_type', 'interval_type', 'next_due_on']);

it('stars, filters and groups service records', function (string $group): void {
    $manager = rolloutSupportManager();
    $open = MaintenanceTask::factory()->create(['title' => 'Replace the compressor']);
    $closed = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::Closed]);

    Livewire::actingAs($manager)
        ->test(ListServiceRecords::class)
        ->assertSee(__('Starred'))
        ->callTableColumnAction('is_favorited', $open)
        ->call('selectTableView', 'preset', 'starred')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed])
        ->call('selectTableView', 'preset', 'all')
        ->filterTable('queryBuilder', operationsRolloutRule('status', 'is', ['values' => [MaintenanceStatus::Closed->value]]))
        ->assertCanSeeTableRecords([$closed])
        ->assertCanNotSeeTableRecords([$open])
        ->filterTable('queryBuilder', operationsRolloutRule('title', 'contains', ['text' => 'compressor']))
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed])
        ->resetTableFilters()
        ->set('tableGrouping', $group)
        ->assertCanSeeTableRecords([$open, $closed]);
})->with(['status', 'employee.employee_code', 'due_at', 'created_at']);

it('gives the employee cards the tab bar and query builder without a Starred view', function (): void {
    (new EmployeePermissionSeeder)->run();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $engineer = EmployeeProfile::factory()->create(['job_title' => 'Field engineer']);
    $accountant = EmployeeProfile::factory()->create(['job_title' => 'Accountant']);
    $archived = EmployeeProfile::factory()->create();
    $archived->delete();

    $component = Livewire::actingAs($admin)
        ->test(ListEmployees::class)
        ->assertSet('activeTab', 'all')
        ->assertSeeHtml("selectTableView('preset', 'archived')")
        ->assertDontSeeHtml("selectTableView('preset', 'starred')")
        ->filterTable('queryBuilder', operationsRolloutRule('job_title', 'is', ['values' => ['Field engineer']]))
        ->assertCanSeeTableRecords([$engineer])
        ->assertCanNotSeeTableRecords([$accountant]);

    expect(array_keys($component->instance()->getTable()->getFilter('queryBuilder')?->getConstraints() ?? []))
        ->toContain('job_title', 'employee_code');

    $component
        ->resetTableFilters()
        ->call('selectTableView', 'preset', 'archived')
        ->assertCanSeeTableRecords([$archived->fresh()])
        ->assertCanNotSeeTableRecords([$engineer, $accountant]);
});
