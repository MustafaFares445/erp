<?php

declare(strict_types=1);

use App\Enums\ServiceAppointmentStatus;
use App\Enums\TicketStatus;
use App\Enums\WarrantyFailureCategory;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\SerializedInventoryUnit;
use App\Models\ServiceAppointment;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\TicketSlaMilestone;
use App\Models\User;
use App\Models\WarrantyRecoveryClaim;
use App\Services\Support\SupportReportService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

function serviceManagementReportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('reports backlog, response, resolution, reopen, csat and assignment metrics from persisted ticket facts', function (): void {
    $manager = serviceManagementReportManager();
    $employee = EmployeeProfile::factory()->create();

    Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'created_at' => now()->subHours(6),
        'assigned_employee_id' => $employee->getKey(),
    ]);

    Ticket::factory()->create([
        'status' => TicketStatus::WaitingCustomer,
        'created_at' => now()->subDays(10),
    ]);

    $resolved = Ticket::factory()->create([
        'status' => TicketStatus::Resolved,
        'created_at' => now()->subHours(3),
        'response_sla_started_at' => now()->subMinutes(40),
        'first_response_at' => now()->subMinutes(25),
        'live_at' => now()->subMinutes(120),
        'resolved_at' => now()->subMinutes(20),
        'reopened_count' => 1,
    ]);

    TicketSatisfactionResponse::query()->create([
        'ticket_id' => $resolved->getKey(),
        'customer_id' => $resolved->customer_id,
        'rating' => 5,
        'comment' => 'Excellent support.',
        'submitted_at' => now(),
        'source_channel' => 'customer_app',
    ]);

    $service = app(SupportReportService::class);
    $from = now()->subDay();
    $until = now()->addMinute();

    $aging = $service->backlogAging($manager);
    $response = $service->responseTime($manager, $from, $until);
    $resolution = $service->resolutionTime($manager, $from, $until);
    $reopen = $service->reopenRate($manager, $from, $until);
    $csat = $service->customerSatisfaction($manager, $from, $until);
    $assignment = $service->assignmentLoad($manager);

    expect($aging)
        ->toMatchArray([
            'total' => 2,
            'under_24h' => 1,
            'over_seven_days' => 1,
        ])
        ->and($response)->toMatchArray([
            'count' => 1,
            'average_minutes' => 15.0,
        ])
        ->and($resolution)->toMatchArray([
            'count' => 1,
            'average_minutes' => 100.0,
        ])
        ->and($reopen)->toMatchArray([
            'eligible' => 1,
            'reopened' => 1,
            'reopen_rate_percent' => 100.0,
        ])
        ->and($csat['responses'])->toBe(1)
        ->and($csat['average_rating'])->toBe(5.0)
        ->and($csat['distribution'][5])->toBe(1)
        ->and($assignment)->toHaveCount(1)
        ->and($assignment[0]['employee_id'])->toBe($employee->getKey())
        ->and($assignment[0]['count'])->toBe(1);
});

it('reports SLA compliance and field-service time without inventing a capacity percentage', function (): void {
    $manager = serviceManagementReportManager();
    $employee = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->create();

    TicketSlaMilestone::query()->create([
        'ticket_id' => $ticket->getKey(),
        'key' => 'first_response',
        'target_minutes' => 30,
        'started_at' => now()->subMinutes(25),
        'due_at' => now()->addMinutes(5),
        'completed_at' => now()->subMinutes(5),
    ]);

    TicketSlaMilestone::query()->create([
        'ticket_id' => $ticket->getKey(),
        'key' => 'resolution',
        'target_minutes' => 60,
        'started_at' => now()->subHours(2),
        'due_at' => now()->subHour(),
        'completed_at' => now()->subMinutes(20),
        'breached_at' => now()->subHour(),
    ]);

    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    ServiceAppointment::query()->create([
        'maintenance_task_id' => $task->getKey(),
        'employee_id' => $employee->getKey(),
        'status' => ServiceAppointmentStatus::Completed,
        'scheduled_start_at' => now()->subHours(3),
        'scheduled_end_at' => now()->subHours(2),
        'estimated_duration_minutes' => 60,
        'address_snapshot' => ['address' => 'Test customer site'],
        'checked_in_at' => now()->subMinutes(75),
        'checked_out_at' => now()->subMinutes(15),
        'customer_signature_name' => 'Customer',
    ]);

    $service = app(SupportReportService::class);
    $from = now()->subDay();
    $until = now()->addMinute();

    $sla = $service->slaCompliance($manager, $from, $until);
    $technicians = $service->technicianUtilization($manager, $from, $until);

    expect($sla)->toMatchArray([
        'completed' => 2,
        'compliant' => 1,
        'breached' => 1,
        'compliance_percent' => 50.0,
    ])
        ->and($technicians)->toHaveCount(1)
        ->and($technicians[0]['employee_id'])->toBe($employee->getKey())
        ->and($technicians[0]['appointment_count'])->toBe(1)
        ->and($technicians[0]['scheduled_minutes'])->toBe(60)
        ->and($technicians[0]['actual_on_site_minutes'])->toBe(60)
        ->and($technicians[0])->not->toHaveKey('utilization_percent');
});

it('reports repeat failures and warranty recovery performance from maintenance history', function (): void {
    $manager = serviceManagementReportManager();
    $unit = SerializedInventoryUnit::factory()->create();
    $customer = CustomerProfile::factory()->create();

    foreach (range(1, 2) as $index) {
        MaintenanceRecord::factory()->create([
            'customer_id' => $customer->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'failure_category' => WarrantyFailureCategory::NormalComponentFailure,
            'created_at' => now()->subHours($index),
        ]);
    }

    $claimRecord = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
    ]);

    WarrantyRecoveryClaim::factory()->for($claimRecord, 'maintenanceRecord')->create([
        'claimed_amount_minor' => 10000,
        'approved_amount_minor' => 8000,
        'received_amount_minor' => 5000,
        'created_at' => now(),
    ]);

    $service = app(SupportReportService::class);
    $from = now()->subDay();
    $until = now()->addMinute();

    $repeatFailures = $service->repeatFailures($manager, $from, $until);
    $recovery = $service->warrantyRecoveryPerformance($manager, $from, $until);

    expect($repeatFailures)->toHaveCount(1)
        ->and($repeatFailures[0])->toMatchArray([
            'serialized_inventory_unit_id' => $unit->getKey(),
            'failure_category' => WarrantyFailureCategory::NormalComponentFailure->value,
            'count' => 2,
        ])
        ->and($recovery)->toMatchArray([
            'claims' => 1,
            'claimed_minor' => 10000,
            'approved_minor' => 8000,
            'received_minor' => 5000,
            'outstanding_minor' => 3000,
            'recovery_percent' => 50.0,
        ]);
});
