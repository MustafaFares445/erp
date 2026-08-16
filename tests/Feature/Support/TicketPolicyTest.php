<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use App\Services\Support\TicketLifecycleService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('authorizes page-open identically to the policy for Support Manager, Support Agent, and Reviewer', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    $this->actingAs($manager)->get(TicketResource::getUrl('index'))->assertOk();
    $this->actingAs($agent)->get(TicketResource::getUrl('index'))->assertOk();
    $this->actingAs($reviewer)->get(TicketResource::getUrl('index'))->assertOk();

    expect(app(TicketPolicy::class)->create($manager))->toBeTrue()
        ->and(app(TicketPolicy::class)->create($agent))->toBeFalse()
        ->and(app(TicketPolicy::class)->create($reviewer))->toBeFalse();
});

it('hides the archive action from a Reviewer and a Support Agent but keeps it reachable for a Support Manager', function (): void {
    $ticket = Ticket::factory()->create();

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');
    Livewire::actingAs($reviewer)
        ->test(ListTickets::class)
        ->assertTableActionHidden('archive', $ticket);

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');
    Livewire::actingAs($agent)
        ->test(ListTickets::class)
        ->assertTableActionHidden('archive', $ticket);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');
    Livewire::actingAs($manager)
        ->test(ListTickets::class)
        ->assertTableActionVisible('archive', $ticket);
});

it('applies the same bulk-action permission as the single action to a Support Agent', function (): void {
    Ticket::factory()->count(2)->create();

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    Livewire::actingAs($agent)
        ->test(ListTickets::class)
        ->assertTableBulkActionHidden('archive');
});

it('hides a status-transition action from a Support Agent the ticket is not assigned to', function (): void {
    $owner = User::factory()->admin()->create();
    $owner->assignRole('Support Agent');

    $ownerProfile = EmployeeProfile::factory()->create(['user_id' => $owner->id]);

    $otherAgent = User::factory()->admin()->create();
    $otherAgent->assignRole('Support Agent');
    EmployeeProfile::factory()->create(['user_id' => $otherAgent->id]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Assigned,
        'assigned_employee_id' => $ownerProfile->getKey(),
    ]);

    Livewire::actingAs($ownerProfile->user)
        ->test(ListTickets::class)
        ->assertTableActionVisible('startProgress', $ticket);

    Livewire::actingAs($otherAgent)
        ->test(ListTickets::class)
        ->assertTableActionHidden('startProgress', $ticket);

    expect(fn () => app(TicketLifecycleService::class)->transition($ticket, TicketStatus::InProgress, $otherAgent))
        ->toThrow(AuthorizationException::class);
});

it('rejects a direct policy call from an unauthorized role exactly as the equivalent UI action would be', function (): void {
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    expect(app(TicketPolicy::class)->create($agent))->toBeFalse()
        ->and(app(TicketPolicy::class)->deleteAny($agent))->toBeFalse()
        ->and(app(TicketPolicy::class)->restoreAny($agent))->toBeFalse();
});
