<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\SupportReports\Pages\ViewSupportReports;
use App\Filament\Resources\SupportReports\SupportReportResource;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Models\AuditLog;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Policies\AuditLogPolicy;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use App\Services\Support\SupportReportService;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function makeReportSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('supports searching and filtering across the ticket, maintenance-request, and service-record lists', function (): void {
    $manager = makeReportSupportManager();
    $ticket = Ticket::factory()->create(['title' => 'Report-visible ticket', 'priority' => TicketPriority::Urgent]);
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create();

    Livewire::actingAs($manager)->test(ListTickets::class)
        ->assertCanSeeTableRecords([$ticket])
        ->filterTable('priority', TicketPriority::Urgent->value)
        ->assertCanSeeTableRecords([$ticket]);

    Livewire::actingAs($manager)->test(ListMaintenanceRequests::class)->assertCanSeeTableRecords([$record]);
    Livewire::actingAs($manager)->test(ListServiceRecords::class)->assertCanSeeTableRecords([$task]);
});

it('shows the open-ticket workload broken down by status, priority, and assignee', function (): void {
    $manager = makeReportSupportManager();
    $profile = EmployeeProfile::factory()->create();
    Ticket::factory()->create(['status' => TicketStatus::Live, 'priority' => TicketPriority::High]);
    Ticket::factory()->create(['status' => TicketStatus::Assigned, 'priority' => TicketPriority::Urgent, 'assigned_employee_id' => $profile->id]);
    Ticket::factory()->create(['status' => TicketStatus::Closed]);

    $workload = app(SupportReportService::class)->workload($manager);

    expect($workload['total_open'])->toBe(2)
        ->and($workload['by_status'][TicketStatus::Live->value] ?? 0)->toBe(1)
        ->and($workload['by_status'][TicketStatus::Assigned->value] ?? 0)->toBe(1)
        ->and($workload['by_priority'][TicketPriority::Urgent->value] ?? 0)->toBe(1)
        ->and($workload['by_assignee'])->toHaveCount(1)
        ->and($workload['by_assignee'][0]['count'])->toBe(1);
});

it('rejects a report request from a user without the report.view permission', function (): void {
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    expect(fn () => app(SupportReportService::class)->workload($agent))
        ->toThrow(DomainException::class);
});

it('shows sla breach counts and average resolution time for the chosen period', function (): void {
    $manager = makeReportSupportManager();
    $service = app(TicketLifecycleService::class);

    $breached = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $service->transition($breached, TicketStatus::Live, $manager);
    $breached->update(['response_breached' => true, 'resolution_breached' => true]);

    $resolved = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $service->transition($resolved, TicketStatus::Live, $manager);
    $resolved->update(['live_at' => now()->subHour(), 'resolved_at' => now()]);

    $report = app(SupportReportService::class)->sla($manager, now()->subDay(), now()->addDay());

    expect($report['response_breaches'])->toBeGreaterThanOrEqual(1)
        ->and($report['resolution_breaches'])->toBeGreaterThanOrEqual(1)
        ->and($report['average_resolution_minutes'])->not->toBeNull();
});

it('counts a just-passed-due ticket as breached in the report and the list, without running the scheduled sweep', function (): void {
    $manager = makeReportSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);

    $this->travel(5)->hours();

    // No support:sla:reconcile run has happened — the stored flags are still false.
    expect($ticket->refresh()->response_breached)->toBeFalse()
        ->and($ticket->resolution_breached)->toBeFalse();

    $report = app(SupportReportService::class)->sla($manager, now()->subDay(), now()->addDay());

    expect($report['response_breaches'])->toBeGreaterThanOrEqual(1)
        ->and($report['resolution_breaches'])->toBeGreaterThanOrEqual(1);

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->filterTable('response_breached', true)
        ->assertCanSeeTableRecords([$ticket])
        ->filterTable('response_breached', false)
        ->assertCanNotSeeTableRecords([$ticket]);
});

it('shows open maintenance requests, overdue service records, and parts consumed per period', function (): void {
    $manager = makeReportSupportManager();
    MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    $overdueRecord = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    MaintenanceTask::factory()->for($overdueRecord, 'maintenanceRecord')->create(['status' => MaintenanceStatus::InProgress, 'due_at' => now()->subDay()]);

    $stock = InventoryStock::factory()->create(['on_hand_quantity' => 10, 'available_quantity' => 10, 'reserved_quantity' => 0, 'damaged_quantity' => 0]);
    $lot = InventoryLot::factory()->for($stock->productVariant)->for($stock->warehouse)->create(['on_hand_quantity' => '10.000000']);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);
    app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 1.0, $manager, $lot->getKey());

    $report = app(SupportReportService::class)->maintenance($manager, now()->subDay(), now()->addDay());

    expect($report['open_requests'])->toBeGreaterThanOrEqual(2)
        ->and($report['overdue_service_records'])->toBeGreaterThanOrEqual(1)
        ->and($report['parts_consumed'])->toBeGreaterThanOrEqual(1);
});

it('produces a retrievable audit entry with actor, timestamp, and changed values for every SC-012 sensitive action', function (): void {
    $admin = User::factory()->admin()->create();
    $manager = makeReportSupportManager();

    $ticket = app(TicketIntakeService::class)->create([
        'customer_id' => CustomerProfile::factory()->create()->id,
        'type' => TicketType::GeneralSupport->value,
        'priority' => TicketPriority::Normal->value,
        'title' => 'Audit coverage ticket',
        'description' => 'Description',
        'is_chargeable' => true,
        'amount' => 50,
        'currency' => 'USD',
    ], $admin); // ticket creation

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Cancelled, $manager); // ticket transition (and closure-shaped event)

    $liveTicket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($liveTicket, TicketStatus::Live, $manager);
    $profile = EmployeeProfile::factory()->create();
    app(TicketLifecycleService::class)->assign($liveTicket, $profile, $manager); // assignment

    $chargeableTicket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($chargeableTicket)->create();
    app(TicketPaymentService::class)->settle($link, 'REF-AUDIT', $admin); // settlement

    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::InProgress, $manager); // maintenance transition

    $task = MaintenanceTask::factory()->for($record, 'maintenanceRecord')->create(['status' => MaintenanceStatus::Open]);
    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $manager); // service-record transition

    $stock = InventoryStock::factory()->create(['on_hand_quantity' => 5, 'available_quantity' => 5, 'reserved_quantity' => 0, 'damaged_quantity' => 0]);
    $lot = InventoryLot::factory()->for($stock->productVariant)->for($stock->warehouse)->create(['on_hand_quantity' => '5.000000']);
    $part = app(ServiceRecordPartService::class)->consume($task, $stock->product_variant_id, $stock->warehouse_id, 1.0, $manager, $lot->getKey()); // parts consumption
    app(ServiceRecordPartService::class)->reverse($part, $admin); // consumption reversal

    $actions = [
        'support.ticket.created',
        'support.ticket.status_changed',
        'support.ticket.assigned',
        'support.payment_link.settled',
        'support.maintenance_record.status_changed',
        'support.service_record.status_changed',
        'support.service_record_part.consumed',
        'support.service_record_part.reversed',
    ];

    foreach ($actions as $action) {
        $entry = AuditLog::query()->where('description', $action)->latest('id')->first();

        expect($entry)->not->toBeNull('Expected a retrievable audit entry for '.$action)
            ->and($entry->causer_id)->not->toBeNull()
            ->and($entry->created_at)->not->toBeNull()
            ->and($entry->attribute_changes)->not->toBeEmpty();
    }
});

it('allows System Admin, Support Manager, and Reviewer to view reports and audit; denies Support Agent', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $manager = makeReportSupportManager();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $service = app(SupportReportService::class);

    expect($service->canView($admin))->toBeTrue()
        ->and($service->canView($manager))->toBeTrue()
        ->and($service->canView($reviewer))->toBeTrue()
        ->and($service->canView($agent))->toBeFalse();

    expect(app(AuditLogPolicy::class)->viewAny($admin))->toBeTrue()
        ->and(app(AuditLogPolicy::class)->viewAny($manager))->toBeTrue()
        ->and(app(AuditLogPolicy::class)->viewAny($reviewer))->toBeTrue()
        ->and(app(AuditLogPolicy::class)->viewAny($agent))->toBeFalse();
});

it('renders the report page with the workload, sla, and maintenance sections, reacting to the period filter', function (): void {
    $manager = makeReportSupportManager();
    Ticket::factory()->create(['status' => TicketStatus::Live]);

    Livewire::actingAs($manager)
        ->test(ViewSupportReports::class)
        ->assertSuccessful()
        ->assertSee('Workload')
        ->assertSee('SLA')
        ->assertSee('Maintenance')
        ->set('from', now()->subWeek()->toDateString())
        ->set('until', now()->toDateString())
        ->assertSuccessful();
});

it('covers report resource metadata, a no-op form, and the canAccess/canViewAny gate', function (): void {
    $manager = makeReportSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    expect(SupportReportResource::getNavigationLabel())->toBe(__('admin.resources.support_reports'))
        ->and(SupportReportResource::form(Schema::make())->getComponents())->toBe([])
        ->and(SupportReportResource::canCreate())->toBeFalse();

    test()->actingAs($manager);
    expect(SupportReportResource::canAccess())->toBeTrue()
        ->and(SupportReportResource::canViewAny())->toBeTrue();

    test()->actingAs($agent);
    expect(SupportReportResource::canAccess())->toBeFalse();
});
