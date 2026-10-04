<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

it('renders a real case workspace with conversation and context sidebar', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeHtml('data-testid="ticket-case-workspace"')
        ->assertSeeHtml('data-testid="ticket-conversation"')
        ->assertSeeHtml('data-testid="ticket-context-sidebar"')
        ->assertSee('Intake')
        ->assertSee('Triage required')
        ->assertSee('Triage equipment and choose the service path');
});

it('posts the conversation from the workspace and displays the first-response marker', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->set('replyMessage', 'Customer-visible troubleshooting update.')
        ->call('postMessage')
        ->assertHasNoErrors()
        ->assertSee('Customer-visible troubleshooting update.')
        ->assertSee('First response');

    expect($ticket->refresh()->first_response_at)->not->toBeNull()
        ->and($ticket->messages()->count())->toBe(1);
});

it('keeps internal notes inside the agent workspace without consuming first response', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->set('replyMessage', 'Escalate internally.')
        ->set('replyInternalNote', true)
        ->call('postMessage')
        ->assertHasNoErrors()
        ->assertSee('Escalate internally.')
        ->assertSee('Internal note');

    expect($ticket->refresh()->first_response_at)->toBeNull();
});

it('shows the primary queue columns and streamlined preset tabs', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$ticket])
        ->assertTableColumnExists('workspace_stage')
        ->assertTableColumnExists('next_action')
        ->assertTableColumnExists('blocked_by')
        ->assertTableColumnStateSet('next_action', 'Triage equipment and choose the service path', $ticket)
        ->assertSee('My Queue')
        ->assertSee('Needs Triage')
        ->assertSee('SLA Risk');
});

it('falls back to the legacy infolist when the rollout switch is disabled', function (): void {
    config()->set('support.workspace_v2_enabled', false);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSeeHtml('data-testid="ticket-case-workspace"')
        ->assertSee('Support workspace');
});

it('lists the conversation chronologically and marks the earliest staff reply as first response', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'first_response_at' => now()->subDays(10)]);

    // The older reply has the LOWER id; a newer one is inserted afterwards with a later timestamp.
    TicketMessage::factory()->create(['ticket_id' => $ticket->getKey(), 'sender_user_id' => $manager->getKey(), 'message' => 'Alpha earliest reply', 'is_internal_note' => false, 'created_at' => now()->subDays(10)]);
    TicketMessage::factory()->create(['ticket_id' => $ticket->getKey(), 'sender_user_id' => $manager->getKey(), 'message' => 'Beta follow up', 'is_internal_note' => false, 'created_at' => now()->subDay()]);
    TicketMessage::factory()->create(['ticket_id' => $ticket->getKey(), 'sender_user_id' => $manager->getKey(), 'message' => 'Gamma back-dated import', 'is_internal_note' => false, 'created_at' => now()->subDays(9)]);

    $html = Livewire::actingAs($manager)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSuccessful()
        ->html();

    expect(mb_strpos($html, 'Alpha earliest reply'))->toBeLessThan(mb_strpos($html, 'Gamma back-dated import'))
        ->and(mb_strpos($html, 'Gamma back-dated import'))->toBeLessThan(mb_strpos($html, 'Beta follow up'))
        ->and(mb_substr_count($html, 'First response</span>') + mb_substr_count($html, '>First response<'))->toBeGreaterThan(0);
});
