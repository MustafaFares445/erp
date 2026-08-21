<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Filament\Resources\MaintenanceRequests\Pages\ViewMaintenanceRequest;
use App\Filament\Resources\MaintenanceRequests\RelationManagers\ServiceRecordsRelationManager;
use App\Filament\Resources\ServiceRecords\Pages\ViewServiceRecord;
use App\Filament\Resources\ServiceRecords\RelationManagers\ConsumedPartsRelationManager;
use App\Filament\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Filament\Resources\Tickets\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Tickets\RelationManagers\MessagesRelationManager;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function makeRenderSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('renders the ticket view page, its infolist, and both relation managers', function (): void {
    $manager = makeRenderSupportManager();
    $profile = EmployeeProfile::factory()->create();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live, 'continued_from_ticket_id' => null]);
    app(TicketLifecycleService::class)->assign($ticket, $profile, $manager);

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

/*
 * Two paths in the ticket surfaces that no test reached before spec 017's
 * coverage pass found them. Both are small and both are the kind of thing that
 * fails silently: a broken continuation link renders as plain text, and a
 * miswired breach filter quietly returns the wrong rows.
 */

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
