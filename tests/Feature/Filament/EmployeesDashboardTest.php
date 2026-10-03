<?php

declare(strict_types=1);

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Enums\SalesOpportunityStatus;
use App\Filament\Pages\EmployeesDashboard;
use App\Filament\Widgets\EmployeesOverdueTasks;
use App\Filament\Widgets\EmployeesStatistics;
use App\Filament\Widgets\EmployeesTaskStatusChart;
use App\Filament\Widgets\EmployeesTaskTrend;
use App\Filament\Widgets\EmployeesTopPerformers;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

function employeesViewer(EmployeePermission ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(static fn (EmployeePermission $permission): string => $permission->value, $permissions));

    return $user;
}

/**
 * @param  array<string, mixed>  $filters
 * @return list<Stat>
 */
function employeesStats(array $filters = []): array
{
    $widget = app(EmployeesStatistics::class);
    $widget->pageFilters = $filters;

    /** @var list<Stat> */
    return new ReflectionMethod($widget, 'getStats')->invoke($widget);
}

it('denies dashboard access without an employees permission', function (): void {
    $this->actingAs(User::factory()->create());

    expect(EmployeesDashboard::canAccess())->toBeFalse();
});

it('grants dashboard access with the employee view permission', function (): void {
    $this->actingAs(employeesViewer(EmployeePermission::EmployeeView));

    expect(EmployeesDashboard::canAccess())->toBeTrue();
});

it('grants dashboard access with the task view permission alone', function (): void {
    $this->actingAs(employeesViewer(EmployeePermission::TaskView));

    expect(EmployeesDashboard::canAccess())->toBeTrue()
        ->and(EmployeesOverdueTasks::canView())->toBeTrue()
        ->and(EmployeesTopPerformers::canView())->toBeFalse();
});

it('gates the statistics and chart widgets behind the same permissions', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(EmployeesStatistics::canView())->toBeFalse()
        ->and(EmployeesTaskTrend::canView())->toBeFalse()
        ->and(EmployeesTaskStatusChart::canView())->toBeFalse();

    $user->givePermissionTo(EmployeePermission::TaskView->value);

    expect(EmployeesStatistics::canView())->toBeTrue()
        ->and(EmployeesTaskTrend::canView())->toBeTrue()
        ->and(EmployeesTaskStatusChart::canView())->toBeTrue();

    $this->actingAs(employeesViewer(EmployeePermission::EmployeeView));

    expect(EmployeesStatistics::canView())->toBeTrue()
        ->and(EmployeesTaskTrend::canView())->toBeTrue()
        ->and(EmployeesTaskStatusChart::canView())->toBeTrue();
});

it('reports work done in the period and the live task and review queues', function (): void {
    PlanTask::factory()->create(['status' => PlanTaskStatus::Pending]);
    PlanTask::factory()->create(['status' => PlanTaskStatus::InProgress, 'due_at' => Carbon::today()->subDays(2)]);
    PlanTask::factory()->completed()->count(2)->create();
    PlanTask::factory()->completedWithTimestamp(Carbon::now()->subDays(40))->create();
    PlanTask::factory()->create(['status' => PlanTaskStatus::Cancelled]);

    CustomerVisit::factory()->create(['planned_at' => Carbon::today()->addHours(2)]);
    CustomerVisit::factory()->create(['planned_at' => Carbon::today()->subDays(90)]);

    SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);
    SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Approved]);

    $stats = employeesStats();

    // Other factories (e.g. an opportunity's transcription) create visits and
    // tasks of their own, so the live counts are read back from the database.
    $visitsThisPeriod = CustomerVisit::query()->where('planned_at', '>=', now()->subDays(29)->startOfDay())->count();
    $openTasks = PlanTask::query()->whereIn('status', [PlanTaskStatus::Pending, PlanTaskStatus::InProgress])->count();

    expect(array_map(fn (Stat $stat): mixed => $stat->getValue(), $stats))->toBe(['2', (string) $visitsThisPeriod, (string) $openTasks, '1'])
        ->and((string) $stats[0]->getDescription())->toBe('100.0% increase')
        ->and(array_sum($stats[0]->getChart() ?? []))->toBe(2)
        ->and((string) $stats[1]->getDescription())->toBe('New this period')
        ->and((string) $stats[2]->getDescription())->toBe('1 overdue')
        ->and($stats[2]->getColor())->toBe('danger')
        ->and($stats[3]->getColor())->toBe('warning');
});

it('shows clear queues in green', function (): void {
    $stats = employeesStats();

    expect($stats[2]->getColor())->toBe('success')
        ->and($stats[3]->getColor())->toBe('success');
});

it('narrows every figure to the selected employee', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $employee->id]);

    PlanTask::factory()->completed()->create(['sales_plan_id' => $plan->id]);
    PlanTask::factory()->completed()->create();
    CustomerVisit::factory()->create(['employee_id' => $employee->id, 'planned_at' => now()]);
    CustomerVisit::factory()->create(['planned_at' => now()]);
    SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft, 'owner_id' => $employee->user_id]);
    SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);

    $values = array_map(fn (Stat $stat): mixed => $stat->getValue(), employeesStats(['employeeId' => $employee->id]));

    expect($values)->toBe(['1', '1', '0', '1']);
});

it('charts completed tasks for the selected and previous period', function (): void {
    PlanTask::factory()->completed()->count(2)->create();
    PlanTask::factory()->completedWithTimestamp(Carbon::now()->subDays(35))->create();
    PlanTask::factory()->completedWithTimestamp(Carbon::now()->subYear())->create();

    $widget = app(EmployeesTaskTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line')
        ->and($widget->getHeading())->toBe('Tasks completed')
        ->and($data['labels'])->toHaveCount(30)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2)
        ->and(array_sum($data['datasets'][1]['data']))->toBe(1);
});

it('splits tasks due in the period by status', function (): void {
    PlanTask::factory()->create(['status' => PlanTaskStatus::Pending, 'due_at' => Carbon::today()]);
    PlanTask::factory()->completed()->create(['due_at' => Carbon::today()]);
    PlanTask::factory()->create(['status' => PlanTaskStatus::Pending, 'due_at' => Carbon::today()->addYear()]);

    $widget = app(EmployeesTaskStatusChart::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('doughnut')
        ->and($data['datasets'][0]['data'])->toBe([1, 0, 1, 0])
        ->and($data['labels'])->toBe(array_map(static fn (PlanTaskStatus $status): string => $status->label(), PlanTaskStatus::cases()));
});

it('ranks employees by tasks completed and visits', function (): void {
    $this->actingAs(employeesViewer(EmployeePermission::EmployeeView));

    $star = EmployeeProfile::factory()->create();
    $idle = EmployeeProfile::factory()->create();
    $plan = SalesPlan::factory()->create(['employee_id' => $star->id]);
    PlanTask::factory()->completed()->count(3)->create(['sales_plan_id' => $plan->id]);
    CustomerVisit::factory()->create(['employee_id' => $star->id, 'planned_at' => now()]);

    Livewire::test(EmployeesTopPerformers::class)
        ->assertSee('Top employees')
        ->assertSee((string) $star->user?->name)
        ->assertDontSee((string) $idle->user?->name);

    Livewire::test(EmployeesTopPerformers::class, ['pageFilters' => ['employeeId' => $idle->id]])
        ->assertDontSee((string) $star->user?->name);
});

it('lists overdue open tasks only', function (): void {
    $this->actingAs(employeesViewer(EmployeePermission::TaskView));

    $overdue = PlanTask::factory()->create(['status' => PlanTaskStatus::Pending, 'due_at' => Carbon::today()->subDays(3)]);
    $onTime = PlanTask::factory()->create(['status' => PlanTaskStatus::Pending, 'due_at' => Carbon::today()->addDays(3)]);
    $done = PlanTask::factory()->completed()->create(['due_at' => Carbon::today()->subDays(3)]);

    Livewire::test(EmployeesOverdueTasks::class)
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$onTime, $done]);
});

it('lays out the employees dashboard in aligned pairs with an employee filter', function (): void {
    $this->actingAs(employeesViewer(EmployeePermission::EmployeeView, EmployeePermission::TaskView));

    expect(new ReflectionMethod(EmployeesDashboard::class, 'getDashboardWidgets')->invoke(new EmployeesDashboard))->toBe([
        EmployeesStatistics::class,
        [EmployeesTaskTrend::class, EmployeesTaskStatusChart::class],
        [EmployeesTopPerformers::class, EmployeesOverdueTasks::class],
    ]);

    Livewire::test(EmployeesDashboard::class)
        ->assertSuccessful()
        ->assertSee('Employee');
});
