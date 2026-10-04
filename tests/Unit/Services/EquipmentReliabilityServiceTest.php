<?php

declare(strict_types=1);

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Services\Support\EquipmentReliabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates MTTR MTBF and repeat failure counts from corrective history', function (): void {
    $unit = SerializedInventoryUnit::factory()->create();

    MaintenanceRecord::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'maintenance_kind' => MaintenanceKind::Corrective,
        'status' => MaintenanceStatus::Closed,
        'failure_category' => 'normal_component_failure',
        'failure_started_at' => now()->subDays(10),
        'service_restored_at' => now()->subDays(10)->addHours(2),
    ]);

    MaintenanceRecord::factory()->create([
        'serialized_inventory_unit_id' => $unit->id,
        'maintenance_kind' => MaintenanceKind::Corrective,
        'status' => MaintenanceStatus::Closed,
        'failure_category' => 'normal_component_failure',
        'failure_started_at' => now()->subDays(5),
        'service_restored_at' => now()->subDays(5)->addHours(4),
    ]);

    $metrics = app(EquipmentReliabilityService::class)->metrics($unit);

    expect($metrics->correctiveFailureCount)->toBe(2)
        ->and($metrics->mttrMinutes)->toBe(180.0)
        ->and($metrics->mtbfHours)->not->toBeNull()
        ->and($metrics->repeatFailureCategories['normal_component_failure'])->toBe(2);
});

it('returns null reliability rates when history is insufficient', function (): void {
    $unit = SerializedInventoryUnit::factory()->create();

    $metrics = app(EquipmentReliabilityService::class)->metrics($unit);

    expect($metrics->mttrMinutes)->toBeNull()
        ->and($metrics->mtbfHours)->toBeNull()
        ->and($metrics->correctiveFailureCount)->toBe(0);
});
