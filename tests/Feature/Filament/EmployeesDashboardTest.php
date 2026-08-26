<?php

declare(strict_types=1);

use App\Enums\EmployeePermission;
use App\Enums\PlanTaskStatus;
use App\Enums\SalesOpportunityStatus;
use App\Filament\Pages\EmployeesDashboard;
use App\Filament\Widgets\EmployeesStatistics;
use App\Filament\Widgets\EmployeesTaskTrend;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\SalesOpportunity;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('denies dashboard access without an employees permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(EmployeesDashboard::canAccess())->toBeFalse();
});

it('grants dashboard access with the employee view permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(EmployeePermission::EmployeeView->value);

    $this->actingAs($user);

    expect(EmployeesDashboard::canAccess())->toBeTrue();
});

it('grants dashboard access with the task view permission alone', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(EmployeePermission::TaskView->value);

    $this->actingAs($user);

    expect(EmployeesDashboard::canAccess())->toBeTrue();
});

it('gates the statistics widget behind the same permissions', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(EmployeesStatistics::canView())->toBeFalse();

    $user->givePermissionTo(EmployeePermission::TaskView->value);

    expect(EmployeesStatistics::canView())->toBeTrue();
});

it('reports correct counts across employees, tasks, visits, and opportunities', function (): void {
    EmployeeProfile::factory()->count(2)->create();
    EmployeeProfile::factory()->inactive()->create();

    PlanTask::factory()->create(['status' => PlanTaskStatus::Pending]);
    PlanTask::factory()->create(['status' => PlanTaskStatus::InProgress]);
    PlanTask::factory()->completed()->create();
    PlanTask::factory()->create(['status' => PlanTaskStatus::Cancelled]);

    CustomerVisit::factory()->create(['planned_at' => Carbon::today()->addHours(2)]);
    CustomerVisit::factory()->create(['planned_at' => Carbon::today()->subDays(3)]);

    SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);
    SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Approved]);

    $widget = app(EmployeesStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat): mixed => $stat->getValue(), $stats);

    expect($values)->toBe([2, 2, 1, 1]);
});

it('uses a line chart for the task completion trend', function (): void {
    $widget = app(EmployeesTaskTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line');
});

it('buckets completed tasks by month for the trailing six months', function (): void {
    PlanTask::factory()->completedWithTimestamp(Carbon::today()->startOfMonth())->create();
    PlanTask::factory()->completedWithTimestamp(Carbon::today()->startOfMonth())->create();
    PlanTask::factory()->completedWithTimestamp(Carbon::today()->startOfMonth()->subMonths(2))->create();
    PlanTask::factory()->completedWithTimestamp(Carbon::today()->startOfMonth()->subMonths(9))->create();

    $widget = app(EmployeesTaskTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['labels'])->toHaveCount(6)
        ->and($data['labels'][5])->toBe(Carbon::today()->startOfMonth()->format('M Y'))
        ->and($data['datasets'][0]['label'])->toBe('Tasks completed')
        ->and($data['datasets'][0]['data'][5])->toBe(2)
        ->and($data['datasets'][0]['data'][3])->toBe(1)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(3);
});
