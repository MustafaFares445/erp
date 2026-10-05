<?php

declare(strict_types=1);

use App\Enums\CostSource;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ServiceRecordPart;
use App\Services\Support\MaintenanceCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WP-2.9, GAP-MW-09 — job-cost aggregation across parts, labour, and
 * third-party cost, and the coverage percentage a job-cost report needs to
 * be honest about an unknown part cost rather than defaulting it to zero.
 */
it('aggregates parts, labour, and third-party costs into one total', function (): void {
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    ServiceRecordPart::factory()->create([
        'maintenance_task_id' => $task->getKey(),
        'quantity' => '2.000000',
        'unit_cost_minor' => 1000,
        'total_cost_minor' => 2000,
        'cost_source' => CostSource::LastReceivedCost->value,
    ]);
    ServiceRecordPart::factory()->create([
        'maintenance_task_id' => $task->getKey(),
        'quantity' => '1.000000',
        'unit_cost_minor' => 500,
        'total_cost_minor' => 500,
        'cost_source' => CostSource::LastReceivedCost->value,
    ]);

    MaintenanceLabourEntry::factory()->create([
        'maintenance_record_id' => $record->getKey(),
        'total_cost_minor' => 3000,
    ]);

    MaintenanceThirdPartyCost::factory()->create([
        'maintenance_record_id' => $record->getKey(),
        'amount_minor' => 1500,
    ]);

    $cost = app(MaintenanceCostService::class)->jobCost($record->refresh());

    expect($cost['parts_cost_minor'])->toBe(2500)
        ->and($cost['labour_cost_minor'])->toBe(3000)
        ->and($cost['third_party_cost_minor'])->toBe(1500)
        ->and($cost['total_cost_minor'])->toBe(7000)
        ->and($cost['coverage_percent'])->toBe(100.0);
});

it('reports partial coverage when a part has no known cost', function (): void {
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    ServiceRecordPart::factory()->create([
        'maintenance_task_id' => $task->getKey(),
        'unit_cost_minor' => 1000,
        'total_cost_minor' => 1000,
        'cost_source' => CostSource::LastReceivedCost->value,
    ]);
    ServiceRecordPart::factory()->create([
        'maintenance_task_id' => $task->getKey(),
        'unit_cost_minor' => null,
        'total_cost_minor' => null,
        'cost_source' => CostSource::Unknown->value,
    ]);

    $cost = app(MaintenanceCostService::class)->jobCost($record->refresh());

    expect($cost['parts_cost_minor'])->toBe(1000)
        ->and($cost['coverage_percent'])->toBe(50.0);
});

it('excludes a reversed consumption from job cost', function (): void {
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    ServiceRecordPart::factory()->create([
        'maintenance_task_id' => $task->getKey(),
        'unit_cost_minor' => 1000,
        'total_cost_minor' => 1000,
        'cost_source' => CostSource::LastReceivedCost->value,
    ]);
    ServiceRecordPart::factory()->reversed()->create([
        'maintenance_task_id' => $task->getKey(),
        'unit_cost_minor' => null,
        'total_cost_minor' => null,
        'cost_source' => null,
    ]);

    $cost = app(MaintenanceCostService::class)->jobCost($record->refresh());

    expect($cost['parts_cost_minor'])->toBe(1000)
        ->and($cost['coverage_percent'])->toBe(100.0);
});
