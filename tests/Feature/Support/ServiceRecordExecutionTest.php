<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\User;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('captures actual start and completion timestamps through service-record lifecycle transitions', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);
    $service = app(ServiceRecordService::class);

    $service->transition($task, MaintenanceStatus::InProgress, $manager);

    expect($task->refresh()->started_at)->not->toBeNull()
        ->and($task->completed_at)->toBeNull()
        ->and($record->refresh()->status)->toBe(MaintenanceStatus::InProgress);

    $startedAt = $task->started_at;
    $this->travel(15)->minutes();

    $service->transition($task->refresh(), MaintenanceStatus::Closed, $manager, 'Replaced the failed assembly and verified operation.');

    expect($task->refresh()->started_at?->equalTo($startedAt))->toBeTrue()
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->completion_notes)->toBe('Replaced the failed assembly and verified operation.');
});
