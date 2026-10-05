<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\ServiceRecords\Pages\ListServiceRecords;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Filament\Resources\ServiceRecords\RelationManagers\ConsumedPartsRelationManager;
use App\Filament\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MaintenanceRecordsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\WarrantyPolicies\Pages\ListWarrantyPolicies;
use App\Filament\Resources\WarrantyPolicies\Pages\ViewWarrantyPolicy;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WarrantyPolicy;
use App\Services\Support\TicketLifecycleService;
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
        ->assertSee($policy->name);
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
