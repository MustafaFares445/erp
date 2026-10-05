<?php

declare(strict_types=1);

use App\Data\Inventory\BarcodeResolution;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceScheduleOccurrence;
use App\Models\PlanTask;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Services\Calendar\MaintenanceCalendarEventService;
use App\Services\Calendar\VisitCalendarEventService;
use App\Services\Inventory\BarcodeWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function coverage57Resolution(InventoryCountLine $line, ?int $serialId = null): BarcodeResolution
{
    return new BarcodeResolution(
        code: 'coverage',
        kind: $serialId === null ? 'variant' : 'serial',
        productVariantId: (int) $line->product_variant_id,
        sku: 'SKU-COVERAGE',
        barcode: null,
        variantName: 'Coverage variant',
        serializedInventoryUnitId: $serialId,
        serialNumber: $serialId === null ? null : 'SERIAL-COVERAGE',
    );
}

it('projects maintenance calendar occurrences for records schedules and orphaned defensive rows', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Coverage Dental Lab']);
    $schedule = MaintenanceSchedule::factory()->create([
        'customer_id' => $customer->getKey(),
        'name' => 'Coverage schedule',
    ]);
    $record = MaintenanceRecord::factory()->create(['customer_id' => $customer->getKey()]);

    MaintenanceScheduleOccurrence::factory()->create([
        'maintenance_schedule_id' => $schedule->getKey(),
        'maintenance_record_id' => $record->getKey(),
        'due_on' => today(),
    ]);

    $serialSchedule = MaintenanceSchedule::factory()->create([
        'customer_id' => null,
        'name' => 'Serial-only schedule',
    ]);
    $serial = $serialSchedule->serializedInventoryUnit;
    expect($serial)->toBeInstanceOf(SerializedInventoryUnit::class);

    MaintenanceScheduleOccurrence::factory()->create([
        'maintenance_schedule_id' => $serialSchedule->getKey(),
        'maintenance_record_id' => null,
        'due_on' => today()->addDay(),
    ]);

    $events = app(MaintenanceCalendarEventService::class)->between(today(), today()->addDays(2));

    expect($events->get(today()->toDateString()))->toHaveCount(1)
        ->and($events->get(today()->toDateString())->first()['subtitle'])->toBe('Coverage Dental Lab')
        ->and($events->get(today()->toDateString())->first()['url'])->toContain((string) $record->getKey())
        ->and($events->get(today()->addDay()->toDateString())->first()['subtitle'])->toBe((string) $serial->serial_number)
        ->and($events->get(today()->addDay()->toDateString())->first()['url'])->toContain((string) $serialSchedule->getKey());
});

it('projects visit and plan-task calendar events', function (): void {
    $customer = CustomerProfile::factory()->create(['company_name' => 'Visit Coverage Customer']);
    $visit = CustomerVisit::factory()->create([
        'customer_id' => $customer->getKey(),
        'planned_at' => Carbon::parse('2026-10-05 10:30:00'),
    ]);
    $task = PlanTask::factory()->create([
        'customer_id' => $customer->getKey(),
        'title' => 'Coverage follow-up',
        'due_at' => '2026-10-05',
    ]);

    $events = app(VisitCalendarEventService::class)->between(
        Carbon::parse('2026-10-05'),
        Carbon::parse('2026-10-06'),
    );

    $dayEvents = $events->get('2026-10-05');
    $visitEvent = $dayEvents->firstWhere('type', 'visit');
    $taskEvent = $dayEvents->firstWhere('type', 'task');

    expect($visitEvent['type'])->toBe('visit')
        ->and($visitEvent['time'])->toBe('10:30')
        ->and($visitEvent['title'])->toBe('Visit Coverage Customer')
        ->and($visitEvent['url'])->toContain((string) $visit->getKey())
        ->and($taskEvent['type'])->toBe('task')
        ->and($taskEvent['title'])->toBe('Coverage follow-up')
        ->and($taskEvent['url'])->toContain((string) $task->sales_plan_id);
});

it('covers barcode count matching and every record-count mismatch guard', function (): void {
    $service = app(BarcodeWorkflowService::class);
    $count = InventoryCount::factory()->create();
    $line = InventoryCountLine::factory()->create(['inventory_count_id' => $count->getKey()]);
    $otherLine = InventoryCountLine::factory()->create();

    $matches = $service->countMatches($count, coverage57Resolution($line));
    expect($matches->modelKeys())->toContain($line->getKey());

    $actor = User::factory()->create();

    expect(fn () => $service->recordCount($actor, $count, $otherLine, coverage57Resolution($otherLine), '1'))
        ->toThrow(DomainException::class, 'does not belong');

    $wrongVariant = new BarcodeResolution(
        code: 'wrong',
        kind: 'variant',
        productVariantId: (int) $line->product_variant_id + 999999,
        sku: 'WRONG',
        barcode: null,
        variantName: 'Wrong',
    );

    expect(fn () => $service->recordCount($actor, $count, $line, $wrongVariant, '1'))
        ->toThrow(DomainException::class, 'does not match');

    $serial = SerializedInventoryUnit::factory()->create();
    $otherSerial = SerializedInventoryUnit::factory()->create();
    $serialLine = InventoryCountLine::factory()->create([
        'inventory_count_id' => $count->getKey(),
        'serialized_inventory_unit_id' => $serial->getKey(),
    ]);

    $serialMatches = $service->countMatches($count, coverage57Resolution($serialLine, (int) $serial->getKey()));
    expect($serialMatches->modelKeys())->toContain($serialLine->getKey());

    expect(fn () => $service->recordCount(
        $actor,
        $count,
        $serialLine,
        coverage57Resolution($serialLine, (int) $otherSerial->getKey()),
        '1',
    ))->toThrow(DomainException::class, 'serial does not match');
});
