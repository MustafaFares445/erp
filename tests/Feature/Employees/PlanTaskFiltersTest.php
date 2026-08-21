<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\PlanTask;
use App\Models\SalesPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('distinguishes overdue, near-due, and completed tasks', function (): void {
    $admin = User::factory()->admin()->create();
    $plan = SalesPlan::factory()->create();

    $overdue = PlanTask::factory()->overdue()->create(['sales_plan_id' => $plan->id]);
    $dueSoon = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'starts_at' => now()->toDateString(),
        'due_at' => now()->addDays(2)->toDateString(),
    ]);
    $completed = PlanTask::factory()->completed()->create(['sales_plan_id' => $plan->id]);
    $farOut = PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'starts_at' => now()->toDateString(),
        'due_at' => now()->addDays(30)->toDateString(),
    ]);

    Livewire::actingAs($admin)
        ->test(ListTasks::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$dueSoon, $completed, $farOut])
        ->removeTableFilter('overdue')
        ->filterTable('due_soon')
        ->assertCanSeeTableRecords([$dueSoon])
        ->assertCanNotSeeTableRecords([$overdue, $completed, $farOut])
        ->removeTableFilter('due_soon')
        ->filterTable('completed')
        ->assertCanSeeTableRecords([$completed])
        ->assertCanNotSeeTableRecords([$overdue, $dueSoon, $farOut]);
});

it('exposes overdue and due-soon as model query scopes usable outside the dashboard', function (): void {
    $plan = SalesPlan::factory()->create();
    $overdue = PlanTask::factory()->overdue()->create(['sales_plan_id' => $plan->id]);
    PlanTask::factory()->create([
        'sales_plan_id' => $plan->id,
        'starts_at' => now()->toDateString(),
        'due_at' => now()->addDays(30)->toDateString(),
    ]);

    expect(PlanTask::query()->overdue()->pluck('id')->all())->toBe([$overdue->id])
        ->and(PlanTask::query()->overdue()->first()->status)->not->toBe(PlanTaskStatus::Completed);
});
