<?php

declare(strict_types=1);

use App\Enums\SupportAutomationEvent;
use App\Enums\SupportAutomationRunStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\SupportAutomationRule;
use App\Models\SupportAutomationRun;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Services\Support\SupportAutomationEngine;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.support_automation_enabled', true);
    (new SlaPolicySeeder)->run();
});

it('matches safe conditions, executes actions and deduplicates the same event', function (): void {
    $team = SupportTeam::query()->create([
        'code' => 'AUTO-L1',
        'name' => 'Automation L1',
        'assignment_strategy' => 'manual',
        'is_active' => true,
    ]);

    $rule = SupportAutomationRule::query()->create([
        'name' => 'Urgent intake',
        'event_key' => SupportAutomationEvent::TicketCreated,
        'precedence' => 10,
        'is_active' => true,
        'conditions' => [
            ['field' => 'priority', 'operator' => 'equals', 'value' => TicketPriority::Urgent->value],
        ],
        'actions' => [
            ['type' => 'assign_team', 'team_id' => $team->id],
        ],
    ]);

    $ticket = Ticket::factory()->create([
        'priority' => TicketPriority::Urgent,
        'status' => TicketStatus::Pending,
    ]);

    $engine = app(SupportAutomationEngine::class);
    $engine->handle(SupportAutomationEvent::TicketCreated, $ticket, [], 'event-urgent-1');
    $engine->handle(SupportAutomationEvent::TicketCreated, $ticket->refresh(), [], 'event-urgent-1');

    $run = SupportAutomationRun::query()->where('support_automation_rule_id', $rule->id)->sole();

    expect($ticket->refresh()->support_team_id)->toBe($team->id)
        ->and($run->status)->toBe(SupportAutomationRunStatus::Succeeded)
        ->and($run->matched)->toBeTrue()
        ->and(SupportAutomationRun::query()->count())->toBe(1);
});

it('isolates a failed rule and continues to the next rule', function (): void {
    $team = SupportTeam::query()->create([
        'code' => 'AUTO-FALLBACK',
        'name' => 'Fallback',
        'assignment_strategy' => 'manual',
        'is_active' => true,
    ]);

    SupportAutomationRule::query()->create([
        'name' => 'Invalid condition',
        'event_key' => SupportAutomationEvent::TicketCreated,
        'precedence' => 1,
        'is_active' => true,
        'conditions' => [['field' => 'raw_sql', 'operator' => 'equals', 'value' => '1=1']],
        'actions' => [['type' => 'assign_team', 'team_id' => $team->id]],
    ]);

    SupportAutomationRule::query()->create([
        'name' => 'Safe fallback',
        'event_key' => SupportAutomationEvent::TicketCreated,
        'precedence' => 2,
        'is_active' => true,
        'conditions' => [],
        'actions' => [['type' => 'assign_team', 'team_id' => $team->id]],
    ]);

    $ticket = Ticket::factory()->create();

    app(SupportAutomationEngine::class)->handle(
        SupportAutomationEvent::TicketCreated,
        $ticket,
        [],
        'event-failure-isolation',
    );

    expect($ticket->refresh()->support_team_id)->toBe($team->id)
        ->and(SupportAutomationRun::query()->where('status', SupportAutomationRunStatus::Failed->value)->count())->toBe(1)
        ->and(SupportAutomationRun::query()->where('status', SupportAutomationRunStatus::Succeeded->value)->count())->toBe(1);
});

it('emits a deterministic stale-customer event from the scheduled command', function (): void {
    $team = SupportTeam::query()->create([
        'code' => 'STALE',
        'name' => 'Stale follow-up',
        'assignment_strategy' => 'manual',
        'is_active' => true,
    ]);

    SupportAutomationRule::query()->create([
        'name' => 'Stale customer queue',
        'event_key' => SupportAutomationEvent::TicketWaitingCustomerStale,
        'precedence' => 1,
        'is_active' => true,
        'conditions' => [['field' => 'waiting_customer_hours', 'operator' => 'gte', 'value' => 24]],
        'actions' => [['type' => 'assign_team', 'team_id' => $team->id]],
    ]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::WaitingCustomer,
        'waiting_customer_since' => now()->subHours(30),
    ]);

    $this->artisan('support:automation:stale')->assertSuccessful();
    $this->artisan('support:automation:stale')->assertSuccessful();

    expect($ticket->refresh()->support_team_id)->toBe($team->id)
        ->and(SupportAutomationRun::query()->count())->toBe(1);
});

it('does nothing when automation is disabled', function (): void {
    config()->set('support.support_automation_enabled', false);

    $team = SupportTeam::query()->create([
        'code' => 'DISABLED',
        'name' => 'Disabled',
        'assignment_strategy' => 'manual',
        'is_active' => true,
    ]);

    SupportAutomationRule::query()->create([
        'name' => 'Disabled rule',
        'event_key' => SupportAutomationEvent::TicketCreated,
        'precedence' => 1,
        'is_active' => true,
        'conditions' => [],
        'actions' => [['type' => 'assign_team', 'team_id' => $team->id]],
    ]);

    $ticket = Ticket::factory()->create();
    app(SupportAutomationEngine::class)->handle(SupportAutomationEvent::TicketCreated, $ticket);

    expect($ticket->refresh()->support_team_id)->toBeNull()
        ->and(SupportAutomationRun::query()->count())->toBe(0);
});
