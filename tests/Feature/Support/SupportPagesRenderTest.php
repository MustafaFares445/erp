<?php

declare(strict_types=1);

use App\Enums\MaintenanceKind;
use App\Enums\MaintenanceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Resources\MaintenanceRequests\Pages\CreateMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\Pages\ListMaintenanceRequests;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\MaintenanceSchedules\Pages\ListMaintenanceSchedules;
use App\Filament\Resources\MaintenanceSchedules\Pages\ViewMaintenanceSchedule;
use App\Filament\Resources\MaintenanceSchedules\RelationManagers\OccurrencesRelationManager;
use App\Filament\Resources\ServiceRecords\Pages\EditServiceRecord;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Filament\Resources\ServiceRecords\RelationManagers\ConsumedPartsRelationManager;
use App\Filament\Resources\ServiceRecords\ServiceRecordResource;
use App\Filament\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Resources\SupportEquipment\Pages\ListSupportEquipment;
use App\Filament\Resources\SupportReports\Pages\ViewSupportReports;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MaintenanceRecordsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\WarrantyPolicies\Pages\CreateWarrantyPolicy;
use App\Filament\Resources\WarrantyPolicies\Pages\ListWarrantyPolicies;
use App\Filament\Resources\WarrantyPolicies\Pages\ViewWarrantyPolicy;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\User;
use App\Models\WarrantyEntitlement;
use App\Models\WarrantyPolicy;
use App\Services\Support\TicketLifecycleService;
use App\Support\MoneyFormatter;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Database\Seeders\WarrantyPolicySeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('corrects an expired warranty without requiring an expiry date on the maintenance page', function (): void {
    $manager = makeRenderSupportManager();
    $record = MaintenanceRecord::factory()->create();

    Livewire::actingAs($manager)->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('overrideWarranty'), [
            'warranty_status' => 'expired',
            'reason' => 'Warranty term verified as expired.',
        ])
        ->assertHasNoActionErrors();
    expect($record->refresh()->warranty_status->value)->toBe('expired')
        ->and($record->warranty_expiry_date)->toBeNull();
});

it('keeps repair awaiting approval until a customer-funded quotation is accepted', function (): void {
    $manager = makeRenderSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::AwaitingApproval]);
    MaintenanceCoverageLine::factory()->create([
        'maintenance_record_id' => $record->id,
        'customer_amount_minor' => 1000,
    ]);

    Livewire::actingAs($manager)->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('customerApprovedRepair'))
        ->assertNotified('Repair cannot start yet');
    expect($record->refresh()->status)->toBe(MaintenanceStatus::AwaitingApproval);
});

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
    (new WarrantyPolicySeeder)->run();
});

function makeRenderSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('renders the ticket view page and all workflow relation managers', function (): void {
    $manager = makeRenderSupportManager();
    $profile = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live, 'continued_from_ticket_id' => null]);
    app(TicketLifecycleService::class)->assign($ticket, $profile, $manager);
    $maintenance = MaintenanceRecord::factory()->fromTicket()->create(['ticket_id' => $ticket->id]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($ticket->ticket_number);

    Livewire::actingAs($manager)
        ->test(AssignmentsRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
        ->assertSuccessful()
        ->callAction(TestAction::make('assign')->table(), ['employee_id' => $profile->id])
        ->assertHasNoActionErrors();

    Livewire::actingAs($manager)
        ->test(MessagesRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
        ->assertSuccessful()
        ->callAction(TestAction::make('post')->table(), ['message' => 'Hello from the render test', 'is_internal_note' => false])
        ->assertHasNoActionErrors();

    Livewire::actingAs($manager)
        ->test(MaintenanceRecordsRelationManager::class, [
            'ownerRecord' => $ticket,
            'pageClass' => ViewTicket::class,
        ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$maintenance]);

    expect($ticket->refresh()->first_response_at)->not->toBeNull();
});

it('renders the maintenance request view page and its infolist, including a linked ticket and equipment', function (): void {
    $manager = makeRenderSupportManager();
    $ticket = Ticket::factory()->create();
    $record = MaintenanceRecord::factory()->fromTicket()->covered()->create(['ticket_id' => $ticket->id]);

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->assertSuccessful();
});

it('renders the service record view page and its infolist', function (): void {
    $manager = makeRenderSupportManager();
    $task = MaintenanceTask::factory()->create();

    Livewire::actingAs($manager)
        ->test(ViewServiceRecord::class, ['record' => $task->getRouteKey()])
        ->assertSuccessful();
});

it('renders the SLA policies list page', function (): void {
    $manager = makeRenderSupportManager();

    Livewire::actingAs($manager)
        ->test(ListSlaPolicies::class)
        ->assertSuccessful();
});

it('renders warranty policy list and detail pages from the support module', function (): void {
    $manager = makeRenderSupportManager();
    $policy = WarrantyPolicy::query()->where('code', 'STANDARD-12M')->firstOrFail();

    Livewire::actingAs($manager)
        ->test(ListWarrantyPolicies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$policy]);

    Livewire::actingAs($manager)
        ->test(ViewWarrantyPolicy::class, ['record' => $policy->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($policy->name)
        ->assertSee(__('Keep remaining original term'));
});

it('labels each warranty replacement rule on the policy view page', function (string $rule, string $label): void {
    $manager = makeRenderSupportManager();
    $policy = WarrantyPolicy::factory()->create(['replacement_rule' => $rule]);

    Livewire::actingAs($manager)
        ->test(ViewWarrantyPolicy::class, ['record' => $policy->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($label);
})->with([
    'restart full term' => ['restart_full_term', 'Restart full policy term'],
    'manual review' => ['manual_review', 'Manual review'],
]);

it('renders translated warranty replacement rules and compact coverage in Arabic', function (): void {
    $manager = makeRenderSupportManager();
    $policy = WarrantyPolicy::query()->where('code', 'STANDARD-12M')->firstOrFail();
    $originalLocale = app()->getLocale();

    app()->setLocale('ar');

    try {
        Livewire::actingAs($manager)
            ->test(ListWarrantyPolicies::class)
            ->assertSuccessful()
            ->assertSee(__('Parts'))
            ->assertSee(__('Labour'));

        Livewire::actingAs($manager)
            ->test(ViewWarrantyPolicy::class, ['record' => $policy->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('الإبقاء على المدة المتبقية من الضمان الأصلي');
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('renders translated support equipment and unlinked service labels in Arabic', function (): void {
    $manager = makeRenderSupportManager();
    $entitlement = WarrantyEntitlement::factory()->create();
    $record = MaintenanceRecord::factory()->create([
        'is_equipment_unlinked' => true,
        'serial_number' => 'EXT-AR-001',
    ]);
    $task = MaintenanceTask::factory()->create(['maintenance_record_id' => $record->getKey()]);
    $originalLocale = app()->getLocale();

    app()->setLocale('ar');

    try {
        Livewire::actingAs($manager)
            ->test(ListSupportEquipment::class)
            ->assertSuccessful()
            ->assertSee('نشط');

        Livewire::actingAs($manager)
            ->test(ListServiceRecords::class)
            ->assertSuccessful()
            ->assertSee('خارجي / غير مرتبط');

        Livewire::actingAs($manager)
            ->test(ListMaintenanceRequests::class)
            ->assertSuccessful()
            ->assertSee('خارجي / غير مرتبط');

        Livewire::actingAs($manager)
            ->test(ConsumedPartsRelationManager::class, [
                'ownerRecord' => $task,
                'pageClass' => ViewServiceRecord::class,
            ])
            ->assertSuccessful()
            ->assertSee('قطع الغيار');
    } finally {
        app()->setLocale($originalLocale);
    }

    expect($entitlement->state->value)->toBe('active');
});

it('throws a LogicException from each relation manager when its owner record is somehow the wrong type', function (): void {
    $wrongRecord = User::factory()->create();

    $assignments = new AssignmentsRelationManager;
    $assignments->ownerRecord = $wrongRecord;

    $ticketMethod = new ReflectionMethod($assignments, 'ticket');
    expect(fn (): mixed => $ticketMethod->invoke($assignments))
        ->toThrow(LogicException::class, 'Expected the owner record of AssignmentsRelationManager to be a Ticket.');

    $messages = new MessagesRelationManager;
    $messages->ownerRecord = $wrongRecord;

    $ticketMethod = new ReflectionMethod($messages, 'ticket');
    expect(fn (): mixed => $ticketMethod->invoke($messages))
        ->toThrow(LogicException::class, 'Expected the owner record of MessagesRelationManager to be a Ticket.');

    $serviceRecords = new ServiceRecordsRelationManager;
    $serviceRecords->ownerRecord = $wrongRecord;

    $maintenanceRecordMethod = new ReflectionMethod($serviceRecords, 'maintenanceRecord');
    expect(fn (): mixed => $maintenanceRecordMethod->invoke($serviceRecords))
        ->toThrow(LogicException::class, 'Expected the owner record of ServiceRecordsRelationManager to be a MaintenanceRecord.');

    $consumedParts = new ConsumedPartsRelationManager;
    $consumedParts->ownerRecord = $wrongRecord;

    $serviceRecordMethod = new ReflectionMethod($consumedParts, 'serviceRecord');
    expect(fn (): mixed => $serviceRecordMethod->invoke($consumedParts))
        ->toThrow(LogicException::class, 'Expected the owner record of ConsumedPartsRelationManager to be a MaintenanceTask.');
});

it('links a continued ticket back to the one it continues, and omits the link otherwise', function (): void {
    $manager = makeRenderSupportManager();
    $original = Ticket::factory()->create();
    $continuation = Ticket::factory()->create(['continued_from_ticket_id' => $original->getKey()]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $continuation->getRouteKey()])
        ->assertSuccessful()
        ->assertSee($original->ticket_number);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $original->getRouteKey()])
        ->assertSuccessful();
});

it('keeps ticket next actions concise and critical maintenance coverage visible in queue tables', function (): void {
    $manager = makeRenderSupportManager();

    $tickets = Livewire::actingAs($manager)->test(ListTickets::class);
    $nextAction = $tickets->instance()->getTable()->getColumn('next_action');

    expect($nextAction)->not->toBeNull()
        ->and($nextAction->getCharacterLimit())->toBe(52);

    $maintenance = Livewire::actingAs($manager)->test(ListMaintenanceRequests::class);
    $table = $maintenance->instance()->getTable();

    expect($table->getColumn('coverage_decision')?->isToggledHiddenByDefault())->toBeFalse()
        ->and($table->getColumn('warranty_status')?->isToggledHiddenByDefault())->toBeFalse()
        ->and($table->getColumn('customer_responsibility')?->isToggledHiddenByDefault())->toBeTrue()
        ->and($table->getColumn('next_action')?->isToggledHiddenByDefault())->toBeTrue()
        ->and($table->getColumn('billing_type')?->isToggledHiddenByDefault())->toBeTrue()
        ->and($table->getColumn('updated_at')?->isToggledHiddenByDefault())->toBeTrue();
});

it('filters tickets by whether their resolution SLA was breached', function (): void {
    $manager = makeRenderSupportManager();

    $breached = Ticket::factory()->create([
        'resolution_breached' => true,
        'resolution_due_at' => now()->subDay(),
    ]);

    $onTime = Ticket::factory()->create([
        'resolution_breached' => false,
        'resolution_due_at' => now()->addDay(),
    ]);

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->filterTable('resolution_breached', true)
        ->assertCanSeeTableRecords([$breached])
        ->assertCanNotSeeTableRecords([$onTime])
        ->filterTable('resolution_breached', false)
        ->assertCanSeeTableRecords([$onTime])
        ->assertCanNotSeeTableRecords([$breached]);
});

it('executes the diagnosis, coverage, repair, QA, and warranty-correction header workflow', function (): void {
    $manager = makeRenderSupportManager();
    $record = MaintenanceRecord::factory()->covered()->create();

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('recordDiagnosis'), [
            'diagnosis_summary' => 'Power module fails under normal load.',
            'root_cause' => 'Internal component failure.',
            'failure_category' => 'normal_component_failure',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->status->value)->toBe('diagnosing');

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('determineCoverage'), [
            'coverage_decision' => 'fully_covered',
            'coverage_reason' => 'Covered failure during active warranty.',
            'customer_coverage_explanation' => 'Repair approved under warranty.',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->status->value)->toBe('ready_for_repair');

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('overrideWarranty'), [
            'warranty_status' => 'covered',
            'warranty_expiry_date' => now()->addMonths(6)->toDateString(),
            'reason' => 'Corrected from validated warranty paperwork.',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->warranty_status->value)->toBe('covered');

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->callAction(TestAction::make('startRepair'))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('sendToQa'))
        ->assertHasNoActionErrors()
        ->callAction(TestAction::make('completeMaintenance'))
        ->assertHasNoActionErrors();

    expect($record->refresh()->status->value)->toBe('closed');
});

it('shows service record statuses as readable labels on the list and view pages', function (): void {
    $manager = makeRenderSupportManager();
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::QualityAssurance]);

    Livewire::actingAs($manager)
        ->test(ListServiceRecords::class)
        ->assertCanSeeTableRecords([$task])
        ->assertSee(MaintenanceStatus::QualityAssurance->label())
        ->assertDontSee('quality_assurance');

    Livewire::actingAs($manager)
        ->test(ViewServiceRecord::class, ['record' => $task->getRouteKey()])
        ->assertSee(MaintenanceStatus::QualityAssurance->label())
        ->assertDontSee('quality_assurance');
});

it('names maintenance request and service record pages after their navigation entries', function (): void {
    // Release QA 2026-10-10: titles said "Maintenance Record" / "Maintenance Task" and the custody helper was ungrammatical
    $manager = makeRenderSupportManager();
    $record = MaintenanceRecord::factory()->create();
    $task = MaintenanceTask::factory()->create();

    expect(MaintenanceRequestResource::getModelLabel())->toBe('Maintenance Request')
        ->and(ServiceRecordResource::getModelLabel())->toBe('Service Record');

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->assertSee('View Maintenance Request');

    Livewire::actingAs($manager)
        ->test(CreateMaintenanceRequest::class)
        ->assertSee('Create Maintenance Request')
        ->assertSee("Optional. Select equipment already recorded in this customer's custody.");

    Livewire::actingAs($manager)
        ->test(ViewServiceRecord::class, ['record' => $task->getRouteKey()])
        ->assertSee('View Service Record');
});

it('shows each serial once and no pending diagnosis for finished maintenance requests', function (): void {
    // Release QA 2026-10-10: "SN SN019-…" doubled prefix and closed calibration jobs shown as "Pending diagnosis"
    $manager = makeRenderSupportManager();
    $prefixedSerial = MaintenanceRecord::factory()->create(['serial_number' => 'SN019-HANDPIECE-0001', 'is_equipment_unlinked' => true]);
    $externalSerial = MaintenanceRecord::factory()->create(['serial_number' => 'EXT-ST-5521', 'is_equipment_unlinked' => true]);
    $closedCalibration = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'maintenance_kind' => MaintenanceKind::Calibration,
    ]);

    Livewire::actingAs($manager)
        ->test(ListMaintenanceRequests::class)
        ->assertCanSeeTableRecords([$prefixedSerial, $externalSerial, $closedCalibration])
        ->assertSee('SN019-HANDPIECE-0001')
        ->assertDontSee('SN SN019-HANDPIECE-0001')
        ->assertSee('SN EXT-ST-5521')
        ->assertTableColumnFormattedStateSet('coverage_decision', 'Not assessed', $closedCalibration)
        ->assertTableColumnFormattedStateSet('coverage_decision', 'Pending diagnosis', $externalSerial);
});

it('formats maintenance costs as direction-safe money and keeps the workflow stepper compact', function (): void {
    // Release QA 2026-10-10: costs had no currency, AR margin rendered "215.00-", stepper label clipped
    $manager = makeRenderSupportManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::InProgress]);
    MaintenanceThirdPartyCost::factory()->create(['maintenance_record_id' => $record->id, 'amount_minor' => 21500]);
    $cancelled = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Cancelled]);

    $view = Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $record->getRouteKey()])
        ->assertSee("\u{2066}".MoneyFormatter::format(21500)."\u{2069}")
        ->assertSee("\u{2066}".MoneyFormatter::format(-21500)."\u{2069}");

    expect(mb_substr_count($view->html(), 'class="ierp-stepper-step"'))->toBe(7);

    $cancelledView = Livewire::actingAs($manager)
        ->test(ViewMaintenanceRequest::class, ['record' => $cancelled->getRouteKey()]);

    expect(mb_substr_count($cancelledView->html(), 'class="ierp-stepper-step"'))->toBe(8);
});

it('shows service record times without seconds and names consumed parts in the empty state', function (): void {
    // Release QA 2026-10-10: "Sep 30, 2026 17:00:00" and "No service record parts"
    $manager = makeRenderSupportManager();
    $task = MaintenanceTask::factory()->create(['due_at' => '2026-09-30 17:00:00']);

    Livewire::actingAs($manager)
        ->test(ViewServiceRecord::class, ['record' => $task->getRouteKey()])
        ->assertSee('Sep 30, 2026 17:00')
        ->assertDontSee('17:00:00');

    Livewire::actingAs($manager)
        ->test(ConsumedPartsRelationManager::class, [
            'ownerRecord' => $task,
            'pageClass' => EditServiceRecord::class,
        ])
        ->assertSee('No parts')
        ->assertDontSee('service record parts');
});

it('translates schedule due states and uses maintenance wording for schedule notice and occurrences', function (): void {
    // Release QA 2026-10-10: untranslated "Overdue" badge, procurement "lead time" and generic "Occurrences" wording
    $manager = makeRenderSupportManager();
    $overdue = MaintenanceSchedule::factory()->create(['next_due_on' => now()->subDays(3)->toDateString()]);

    Livewire::actingAs($manager)
        ->test(ViewMaintenanceSchedule::class, ['record' => $overdue->getRouteKey()])
        ->assertSee('Advance notice (days)')
        ->assertDontSee('Lead time (days)');

    Livewire::actingAs($manager)
        ->test(OccurrencesRelationManager::class, [
            'ownerRecord' => $overdue,
            'pageClass' => ViewMaintenanceSchedule::class,
        ])
        ->assertSee('Due occurrences');

    $originalLocale = app()->getLocale();
    app()->setLocale('ar');

    try {
        Livewire::actingAs($manager)
            ->test(ListMaintenanceSchedules::class)
            ->assertTableColumnFormattedStateSet('due_state', 'متأخر الاستحقاق', $overdue);
    } finally {
        app()->setLocale($originalLocale);
    }
});

it('isolates the CSAT score so it reads left to right in Arabic', function (): void {
    // Release QA 2026-10-10: CSAT "4.5 / 5" rendered as "5 / 4.5" in Arabic
    $manager = makeRenderSupportManager();

    foreach ([4, 5] as $rating) {
        $ticket = Ticket::factory()->create();
        TicketSatisfactionResponse::query()->create([
            'ticket_id' => $ticket->getKey(),
            'customer_id' => $ticket->customer_id,
            'rating' => $rating,
            'comment' => null,
            'submitted_at' => now(),
            'source_channel' => 'customer_app',
        ]);
    }

    Livewire::actingAs($manager)
        ->test(ViewSupportReports::class)
        ->assertSeeHtml('<bdi dir="ltr">4.5 / 5</bdi>');
});

it('labels the SLA policy active column and colours ticket priorities by urgency', function (): void {
    // Release QA 2026-10-10: "Is active" header and all priority badges in the same blue
    $manager = makeRenderSupportManager();

    $table = Livewire::actingAs($manager)->test(ListSlaPolicies::class)->instance()->getTable();

    expect($table->getColumn('is_active')?->getLabel())->toBe('Active')
        ->and(array_map(static fn (TicketPriority $priority): string => $priority->getColor(), TicketPriority::cases()))
        ->toBe(['gray', 'info', 'warning', 'danger']);
});

it('explains warranty policy terms in plain language and shows full coverage on the list', function (): void {
    // Release QA 2026-10-10: developer jargon, bidi-broken STANDARD-12M example, truncated coverage cell
    $manager = makeRenderSupportManager();
    $policy = WarrantyPolicy::factory()->create([
        'covers_parts' => true,
        'covers_labour' => true,
        'covers_travel' => true,
        'covers_consumables' => true,
        'covers_third_party' => true,
    ]);

    Livewire::actingAs($manager)
        ->test(CreateWarrantyPolicy::class)
        ->assertSeeHtml('<bdi dir="ltr">STANDARD-12M</bdi>')
        ->assertSee('Each sold unit keeps the terms in force on its sale date')
        ->assertSee('What this warranty normally covers.')
        ->assertDontSee('snapshotted')
        ->assertDontSee('claim decision UI');

    Livewire::actingAs($manager)
        ->test(ListWarrantyPolicies::class)
        ->assertTableColumnFormattedStateSet('coverage', 'Parts · Labour · Travel · Consumables · Third-party services', $policy)
        ->assertSee('Parts · Labour · Travel · Consumables · Third-party services');
});
