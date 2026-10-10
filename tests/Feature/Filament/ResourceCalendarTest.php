<?php

declare(strict_types=1);

use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\TasksCalendar;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use App\Services\Calendar\ResourceCalendarEventService;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => (new EmployeePermissionSeeder)->run());

it('registers a distinct calendar companion for all configured operational resources', function (): void {
    foreach (ResourceCalendarEventService::definitions() as $key => $config) {
        [$folder, $singular] = $config;
        $resource = "App\\Filament\\Resources\\{$folder}\\{$singular}Resource";

        expect($resource::getPages())->toHaveKey('calendar');
        expect($resource::getUrl('calendar'))->toContain('/calendar');
    }
});

it('shows the task start and due dates, and preserves the list link', function (): void {
    $admin = User::factory()->admin()->create();
    $month = Carbon::today()->startOfMonth();
    $plan = SalesPlan::factory()->create(['month' => $month->toDateString()]);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'title' => 'Calendar contract task',
        'starts_at' => $month->copy()->addDays(4)->toDateString(),
        'due_at' => $month->copy()->addDays(8)->toDateString(),
    ]);

    $events = app(ResourceCalendarEventService::class)->between(
        'tasks', $month->copy(), $month->copy()->endOfMonth(),
    );

    expect($events->flatten(1)->where('title', $task->title)->count())->toBe(2);

    Livewire::actingAs($admin)->test(ListTasks::class)->assertActionExists('calendar');

    Livewire::actingAs($admin)
        ->test(TasksCalendar::class)
        ->set('date', $month->toDateString())
        ->assertSuccessful()
        ->assertSee('Calendar contract task')
        ->assertSee('List view');
});

it('does not allow a role denied task-table access to open the task calendar', function (): void {
    $user = User::factory()->admin()->create();
    $user->assignRole('Payroll Officer');

    $this->actingAs($user)
        ->get(TaskResource::getUrl('calendar'))
        ->assertForbidden();
});

it('expands a monthly plan across its month and never invents an audit-date event', function (): void {
    $admin = User::factory()->admin()->create();
    $month = Carbon::today()->startOfMonth();
    $plan = SalesPlan::factory()->create([
        'month' => $month->toDateString(),
        'name' => 'Calendar monthly strategy',
    ]);
    $this->actingAs($admin);

    $events = app(ResourceCalendarEventService::class)->between(
        'monthly-plans', $month->copy(), $month->copy()->endOfMonth(),
    );

    expect($events->flatten(1)->where('title', $plan->name)->count())->toBe($month->daysInMonth);
});
