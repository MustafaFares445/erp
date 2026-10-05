<?php

declare(strict_types=1);

use App\Enums\SlaMilestoneKey;
use App\Enums\TicketPriority;
use App\Events\SlaAtRisk;
use App\Models\SlaPolicy;
use App\Models\SlaPolicyMilestone;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\SlaService;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('covers the complete legacy SLA compatibility flow and early-return methods', function (): void {
    config()->set('support.sla_v2_enabled', false);
    (new SlaPolicySeeder)->run();
    Event::fake([SlaAtRisk::class]);

    $service = app(SlaService::class);
    $ticket = Ticket::factory()->withPriority(TicketPriority::Low)->create([
        'response_due_at' => null,
        'resolution_due_at' => null,
        'live_at' => null,
        'waiting_customer_since' => null,
        'response_breached' => false,
        'resolution_breached' => false,
    ]);

    $service->onTicketCreated($ticket);
    $ticket->refresh();

    expect($ticket->response_due_at)->not->toBeNull()
        ->and($ticket->sla_response_target_minutes)->toBe(1440)
        ->and($ticket->sla_resolution_target_minutes)->toBe(4320);

    // Replaying intake and live transitions covers the defensive early returns.
    $originalResponseDue = $ticket->response_due_at?->copy();
    $service->onTicketCreated($ticket);
    expect($ticket->refresh()->response_due_at?->equalTo($originalResponseDue))->toBeTrue();

    $service->onTicketLive($ticket);
    $ticket->refresh();
    expect($ticket->live_at)->not->toBeNull()
        ->and($ticket->resolution_due_at)->not->toBeNull();

    $originalLiveAt = $ticket->live_at?->copy();
    $service->onTicketLive($ticket);
    expect($ticket->refresh()->live_at?->equalTo($originalLiveAt))->toBeTrue();

    $service->onWaitingCustomer($ticket);
    expect($ticket->refresh()->waiting_customer_since)->not->toBeNull();

    $this->travel(30)->minutes();
    $service->onResumeFromWaiting($ticket->refresh());
    expect($ticket->refresh()->waiting_customer_since)->toBeNull()
        ->and($ticket->waiting_customer_accumulated_seconds)->toBeGreaterThanOrEqual(1800);

    $actor = User::factory()->admin()->create();
    $this->travel(5)->days();

    $service->onPriorityChanged($ticket->refresh(), TicketPriority::Urgent, $actor);
    $ticket->refresh();

    expect($ticket->sla_response_target_minutes)->toBe(60)
        ->and($ticket->sla_resolution_target_minutes)->toBe(240)
        ->and($ticket->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();

    $service->refreshBreachFlags($ticket->refresh());

    // These methods intentionally no-op when SLA v2 is disabled.
    $service->completeFirstResponse($ticket);
    $service->completeResolution($ticket);
    $service->completeAssignment($ticket);
    $service->completeOnsiteArrival($ticket);
    $service->reopenResolution($ticket);

    Event::assertDispatched(SlaAtRisk::class);
});

it('uses built-in legacy targets when no stored SLA policy exists', function (): void {
    config()->set('support.sla_v2_enabled', false);
    SlaPolicy::query()->delete();

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create([
        'response_due_at' => null,
    ]);

    app(SlaService::class)->onTicketCreated($ticket);
    $ticket->refresh();

    expect($ticket->sla_response_target_minutes)->toBe(480)
        ->and($ticket->sla_resolution_target_minutes)->toBe(2880)
        ->and($ticket->response_due_at)->not->toBeNull();
});

it('starts active optional SLA milestones when a matching v2 policy goes live', function (): void {
    config()->set('support.sla_v2_enabled', true);
    (new SlaPolicySeeder)->run();

    $policy = SlaPolicy::query()->where('priority', TicketPriority::Normal->value)->firstOrFail();
    SlaPolicyMilestone::query()->create([
        'sla_policy_id' => $policy->id,
        'key' => SlaMilestoneKey::Assignment,
        'target_minutes' => 30,
        'at_risk_before_minutes' => 10,
        'pause_when_waiting_customer' => false,
        'is_active' => true,
        'sort_order' => 30,
    ]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create([
        'response_due_at' => null,
        'live_at' => null,
    ]);

    $service = app(SlaService::class);
    $service->onTicketCreated($ticket);
    $service->onTicketLive($ticket->refresh());

    expect($ticket->slaMilestones()
        ->where('key', SlaMilestoneKey::Assignment->value)
        ->exists())->toBeTrue();
});

it('refreshes both legacy SLA breach flags and dispatches the at-risk event', function (): void {
    config()->set('support.sla_v2_enabled', false);
    Event::fake([SlaAtRisk::class]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create([
        'response_due_at' => now()->subHours(2),
        'resolution_due_at' => now()->subHour(),
        'first_response_at' => null,
        'resolved_at' => null,
        'response_breached' => false,
        'resolution_breached' => false,
    ]);

    app(SlaService::class)->refreshBreachFlags($ticket);
    $ticket->refresh();

    expect($ticket->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();

    Event::assertDispatched(SlaAtRisk::class);
});

it('falls back to legacy resume and priority handling when SLA v2 has no milestone or matching policy', function (): void {
    config()->set('support.sla_v2_enabled', true);
    SlaPolicy::query()->delete();

    $service = app(SlaService::class);
    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create([
        'waiting_customer_since' => now()->subMinutes(15),
        'waiting_customer_accumulated_seconds' => 0,
        'response_due_at' => now()->addHour(),
        'resolution_due_at' => now()->addHours(2),
        'live_at' => now()->subHour(),
    ]);

    $service->onResumeFromWaiting($ticket);
    expect($ticket->refresh()->waiting_customer_since)->toBeNull()
        ->and($ticket->waiting_customer_accumulated_seconds)->toBeGreaterThanOrEqual(900);

    $service->onPriorityChanged($ticket->refresh(), TicketPriority::High, null);
    $ticket->refresh();

    expect($ticket->sla_response_target_minutes)->toBe(240)
        ->and($ticket->sla_resolution_target_minutes)->toBe(1440);
});
