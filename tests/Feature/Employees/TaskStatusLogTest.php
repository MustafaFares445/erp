<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Models\SalesPlan;
use App\Models\TaskStatusLog;
use App\Models\User;
use App\Services\Employees\PlanTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes one append-only status log row with actor, time, and note on every transition', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $plan = SalesPlan::factory()->create(['month' => '2026-03-01']);
    $task = app(PlanTaskService::class)->create($plan, [
        'title' => 'Follow up call',
        'starts_at' => '2026-03-01',
        'due_at' => '2026-03-10',
    ]);

    app(PlanTaskService::class)->transition($task, PlanTaskStatus::InProgress, 'Started working on it');

    $logs = TaskStatusLog::query()->where('plan_task_id', $task->id)->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->from_status)->toBeNull()
        ->and($logs[0]->to_status)->toBe(PlanTaskStatus::Pending)
        ->and($logs[1]->from_status)->toBe(PlanTaskStatus::Pending)
        ->and($logs[1]->to_status)->toBe(PlanTaskStatus::InProgress)
        ->and($logs[1]->note)->toBe('Started working on it')
        ->and($logs[1]->actor_id)->toBe($actor->id)
        ->and($logs[1]->created_at)->not->toBeNull();
});

it('rejects any update to an existing status log row', function (): void {
    $log = TaskStatusLog::factory()->create();

    expect(fn () => $log->update(['note' => 'edited']))->toThrow(DomainException::class);
});

it('rejects deleting a status log row', function (): void {
    $log = TaskStatusLog::factory()->create();

    expect(fn () => $log->delete())->toThrow(DomainException::class);
});
