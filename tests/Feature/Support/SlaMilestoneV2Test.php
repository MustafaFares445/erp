<?php

declare(strict_types=1);

use App\Enums\SlaMilestoneKey;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\SlaService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketMessageService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.sla_v2_enabled', true);
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

it('snapshots first-response and resolution milestones while preserving legacy summary columns', function (): void {
    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Live]);

    app(SlaService::class)->onTicketCreated($ticket);
    $ticket->refresh();

    $response = $ticket->slaMilestones()->where('key', SlaMilestoneKey::FirstResponse->value)->firstOrFail();

    expect($ticket->sla_policy_id)->not->toBeNull()
        ->and($response->target_minutes)->toBe(60)
        ->and($ticket->response_due_at?->equalTo($response->due_at))->toBeTrue()
        ->and($ticket->sla_resolution_target_minutes)->toBe(240);

    app(SlaService::class)->onTicketLive($ticket);
    $ticket->refresh();

    $resolution = $ticket->slaMilestones()->where('key', SlaMilestoneKey::Resolution->value)->firstOrFail();

    expect($resolution->target_minutes)->toBe(240)
        ->and($ticket->resolution_due_at?->equalTo($resolution->due_at))->toBeTrue();
});

it('pauses and resumes the resolution milestone and extends the compatibility due time', function (): void {
    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['status' => TicketStatus::Live]);
    app(SlaService::class)->onTicketCreated($ticket);
    app(SlaService::class)->onTicketLive($ticket);

    $ticket->refresh();
    $before = $ticket->resolution_due_at;

    app(SlaService::class)->onWaitingCustomer($ticket);
    $this->travel(30)->minutes();
    app(SlaService::class)->onResumeFromWaiting($ticket->refresh());
    $ticket->refresh();

    expect((int) $ticket->waiting_customer_accumulated_seconds)->toBeGreaterThanOrEqual(1800)
        ->and($ticket->resolution_due_at?->gt($before))->toBeTrue();
});

it('completes first response from the existing message service', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['status' => TicketStatus::Live]);

    app(SlaService::class)->onTicketCreated($ticket);
    app(TicketMessageService::class)->post($ticket->refresh(), 'First public response', false, $manager);

    $milestone = $ticket->slaMilestones()->where('key', SlaMilestoneKey::FirstResponse->value)->firstOrFail();

    expect($milestone->completed_at)->not->toBeNull()
        ->and($ticket->refresh()->first_response_at)->not->toBeNull();
});

it('completes and reopens the resolution milestone through the ticket lifecycle', function (): void {
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $profile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Live]);
    app(SlaService::class)->onTicketCreated($ticket);
    app(SlaService::class)->onTicketLive($ticket);
    app(TicketLifecycleService::class)->assign($ticket->refresh(), $profile, $manager);
    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);
    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::Resolved, $agent, 'Fixed.');

    $milestone = $ticket->slaMilestones()->where('key', SlaMilestoneKey::Resolution->value)->firstOrFail();
    expect($milestone->completed_at)->not->toBeNull();

    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

    expect($milestone->refresh()->completed_at)->toBeNull();
});
