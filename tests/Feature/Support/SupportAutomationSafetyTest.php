<?php

declare(strict_types=1);

use App\Enums\SupportAutomationEvent;
use App\Enums\SupportAutomationRunStatus;
use App\Enums\TicketPriority;
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

/** @param array<int, mixed> $conditions @param array<int, mixed> $actions */
function automationRule(array $conditions, array $actions, array $attributes = []): SupportAutomationRule
{
    return SupportAutomationRule::query()->create([
        'name' => 'Rule '.fake()->unique()->numberBetween(1, 99999),
        'event_key' => SupportAutomationEvent::TicketCreated,
        'precedence' => 10,
        'is_active' => true,
        'conditions' => $conditions,
        'actions' => $actions,
        ...$attributes,
    ]);
}

it('fails safely and changes nothing for unsupported fields, operators, actions and malformed structures', function (array $conditions, array $actions): void {
    $before = Ticket::factory()->create(['priority' => TicketPriority::Normal]);
    $rule = automationRule($conditions, $actions);

    app(SupportAutomationEngine::class)->handle(SupportAutomationEvent::TicketCreated, $before, [], 'event-malformed');

    $run = SupportAutomationRun::query()->where('support_automation_rule_id', $rule->getKey())->sole();

    expect($run->status)->toBe(SupportAutomationRunStatus::Failed)
        ->and($run->error)->not->toBeEmpty()
        ->and($before->refresh()->priority)->toBe(TicketPriority::Normal)
        ->and($before->support_team_id)->toBeNull();
})->with([
    'unknown field' => [[['field' => 'raw_sql', 'operator' => 'equals', 'value' => '1']], [['type' => 'set_priority', 'priority' => 'urgent']]],
    'unknown operator' => [[['field' => 'priority', 'operator' => 'matches_regex', 'value' => '.*']], [['type' => 'set_priority', 'priority' => 'urgent']]],
    'condition is not an object' => [['priority = normal'], [['type' => 'set_priority', 'priority' => 'urgent']]],
    'missing field' => [[['operator' => 'equals', 'value' => 'x']], [['type' => 'set_priority', 'priority' => 'urgent']]],
    'unknown action type' => [[], [['type' => 'run_php', 'code' => 'system("id");']]],
    'action without a type' => [[], [['priority' => 'urgent']]],
    'invalid priority' => [[], [['type' => 'set_priority', 'priority' => 'apocalyptic']]],
    'team that does not exist' => [[], [['type' => 'assign_team', 'team_id' => 999999]]],
]);

it('rolls back earlier actions of a rule when a later action fails', function (): void {
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Normal]);
    $rule = automationRule([], [
        ['type' => 'set_priority', 'priority' => 'urgent'],
        ['type' => 'run_php', 'code' => 'nope'],
    ]);

    app(SupportAutomationEngine::class)->handle(SupportAutomationEvent::TicketCreated, $ticket, [], 'event-partial');

    expect(SupportAutomationRun::query()->where('support_automation_rule_id', $rule->getKey())->sole()->status)->toBe(SupportAutomationRunStatus::Failed)
        ->and($ticket->refresh()->priority)->toBe(TicketPriority::Normal);
});

it('honours precedence and stop-processing', function (): void {
    $teamA = SupportTeam::query()->create(['code' => 'A', 'name' => 'A', 'assignment_strategy' => 'manual', 'is_active' => true]);
    $teamB = SupportTeam::query()->create(['code' => 'B', 'name' => 'B', 'assignment_strategy' => 'manual', 'is_active' => true]);

    $second = automationRule([], [['type' => 'assign_team', 'team_id' => $teamB->id]], ['precedence' => 20]);
    $first = automationRule([], [['type' => 'assign_team', 'team_id' => $teamA->id]], ['precedence' => 10, 'stop_processing' => true]);

    $ticket = Ticket::factory()->create();

    app(SupportAutomationEngine::class)->handle(SupportAutomationEvent::TicketCreated, $ticket, [], 'event-stop');

    expect($ticket->refresh()->support_team_id)->toBe($teamA->id)
        ->and(SupportAutomationRun::query()->where('support_automation_rule_id', $first->getKey())->sole()->status)->toBe(SupportAutomationRunStatus::Succeeded)
        ->and(SupportAutomationRun::query()->where('support_automation_rule_id', $second->getKey())->exists())->toBeFalse();
});

it('records unmatched rules as skipped and ignores inactive rules and other events', function (): void {
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Low]);

    $unmatched = automationRule([['field' => 'priority', 'operator' => 'equals', 'value' => 'urgent']], [['type' => 'set_priority', 'priority' => 'high']]);
    $inactive = automationRule([], [['type' => 'set_priority', 'priority' => 'high']], ['is_active' => false]);
    $otherEvent = automationRule([], [['type' => 'set_priority', 'priority' => 'high']], ['event_key' => SupportAutomationEvent::TicketTriaged]);

    app(SupportAutomationEngine::class)->handle(SupportAutomationEvent::TicketCreated, $ticket, [], 'event-skip');

    $skipped = SupportAutomationRun::query()->where('support_automation_rule_id', $unmatched->getKey())->sole();

    expect($skipped->status)->toBe(SupportAutomationRunStatus::Skipped)
        ->and($skipped->matched)->toBeFalse()
        ->and(SupportAutomationRun::query()->whereIn('support_automation_rule_id', [$inactive->getKey(), $otherEvent->getKey()])->exists())->toBeFalse()
        ->and($ticket->refresh()->priority)->toBe(TicketPriority::Low);
});

it('does not re-run a rule for the same event uuid but does for a new one', function (): void {
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::Low]);
    $rule = automationRule([], [['type' => 'post_internal_note', 'message' => 'Automated note', 'user_id' => $ticket->customer?->user_id ?? 1]]);

    $engine = app(SupportAutomationEngine::class);
    $engine->handle(SupportAutomationEvent::TicketCreated, $ticket, [], 'same-uuid');
    $engine->handle(SupportAutomationEvent::TicketCreated, $ticket->refresh(), [], 'same-uuid');
    $engine->handle(SupportAutomationEvent::TicketCreated, $ticket->refresh(), [], 'new-uuid');

    expect(SupportAutomationRun::query()->where('support_automation_rule_id', $rule->getKey())->count())->toBe(2);
});
