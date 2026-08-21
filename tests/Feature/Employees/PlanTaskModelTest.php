<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\PlanTask;
use App\Models\TaskStatusLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves its customer and status log relations', function (): void {
    $customer = CustomerProfile::factory()->create();
    $task = PlanTask::factory()->create(['customer_id' => $customer->getKey()]);
    $log = TaskStatusLog::factory()->create(['plan_task_id' => $task->getKey()]);

    expect($task->customer()->first()->is($customer))->toBeTrue()
        ->and($task->statusLogs()->first()->is($log))->toBeTrue();
});
