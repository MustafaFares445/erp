<?php

declare(strict_types=1);

use App\Enums\SupportAutomationEvent;
use App\Enums\SupportAutomationRunStatus;
use App\Enums\TicketAssignmentSource;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Enums\WarrantyStatus;
use App\Models\CollaborationEntry;
use App\Models\CollaborationFollower;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\SerializedInventoryUnit;
use App\Models\SupportAutomationRule;
use App\Models\SupportAutomationRun;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\SupportAutomationActionExecutor;
use App\Services\Support\SupportAutomationConditionEvaluator;
use App\Services\Support\SupportAutomationEngine;
use App\Services\Support\TicketAssignmentService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.support_automation_enabled', true);
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function coverageAutomationTeam(string $code, bool $active = true): SupportTeam
{
    return SupportTeam::query()->create([
        'code' => $code,
        'name' => $code.' team',
        'assignment_strategy' => 'least_loaded',
        'default_capacity' => 10,
        'is_active' => $active,
    ]);
}

function coverageAutomationAgent(): EmployeeProfile
{
    $user = User::factory()->admin()->create();
    $user->assignRole('Support Agent');

    return EmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);
}

function coverageAutomationManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

// ---------------------------------------------------------------------------
// Condition evaluator
// ---------------------------------------------------------------------------

it('reads every supported ticket field when evaluating conditions', function (): void {
    $team = coverageAutomationTeam('EVAL');
    $unit = SerializedInventoryUnit::factory()->create();
    $ticket = Ticket::factory()->create([
        'type' => 'hardware_issue',
        'priority' => TicketPriority::High,
        'status' => TicketStatus::Live,
        'customer_impact' => 'degraded',
        'service_path' => TicketServicePath::OnSiteVisit,
        'support_team_id' => $team->id,
        'serialized_inventory_unit_id' => $unit->id,
        'warranty_status' => WarrantyStatus::Covered,
        'waiting_customer_since' => now()->subHours(30),
    ]);
    $ticket->forceFill(['created_at' => now()->subHours(50)])->save();
    $ticket->refresh();

    $evaluator = new SupportAutomationConditionEvaluator;

    $matching = [
        ['field' => 'type', 'operator' => 'equals', 'value' => 'hardware_issue'],
        ['field' => 'priority', 'operator' => 'equals', 'value' => 'high'],
        ['field' => 'status', 'operator' => 'equals', 'value' => 'live'],
        ['field' => 'customer_impact', 'operator' => 'equals', 'value' => 'degraded'],
        ['field' => 'service_path', 'operator' => 'equals', 'value' => 'on_site_visit'],
        ['field' => 'support_team_id', 'operator' => 'equals', 'value' => (string) $team->id],
        ['field' => 'customer_id', 'operator' => 'equals', 'value' => $ticket->customer_id],
        ['field' => 'product_variant_id', 'operator' => 'equals', 'value' => $unit->product_variant_id],
        ['field' => 'warranty_status', 'operator' => 'equals', 'value' => 'covered'],
        ['field' => 'age_hours', 'operator' => 'gte', 'value' => 49],
        ['field' => 'waiting_customer_hours', 'operator' => 'gte', 'value' => 29],
        ['field' => 'sla_state', 'operator' => 'equals', 'value' => 'ok'],
    ];

    expect($evaluator->matches($ticket, $matching))->toBeTrue();

    foreach (array_filter($matching, static fn (array $condition): bool => $condition['operator'] === 'equals') as $condition) {
        $negated = [[...$condition, 'operator' => 'not_equals']];

        expect($evaluator->matches($ticket, $negated))->toBeFalse($condition['field'].' should not match not_equals');
    }
});

it('evaluates the list, range and null operators', function (): void {
    $ticket = Ticket::factory()->create(['priority' => TicketPriority::High, 'status' => TicketStatus::Live]);
    $evaluator = new SupportAutomationConditionEvaluator;

    $check = static fn (string $field, string $operator, mixed $value = null): bool => $evaluator->matches(
        $ticket,
        [['field' => $field, 'operator' => $operator, 'value' => $value]],
    );

    expect($check('priority', 'in', ['high', 'urgent']))->toBeTrue()
        ->and($check('priority', 'in', ['low']))->toBeFalse()
        ->and($check('priority', 'in', 'high'))->toBeFalse()
        ->and($check('priority', 'not_in', ['low', 'normal']))->toBeTrue()
        ->and($check('priority', 'not_in', ['high']))->toBeFalse()
        ->and($check('priority', 'not_in', 'high'))->toBeFalse()
        ->and($check('customer_id', 'gte', 0))->toBeTrue()
        ->and($check('customer_id', 'gte', 999999999))->toBeFalse()
        ->and($check('priority', 'gte', 1))->toBeFalse()
        ->and($check('customer_id', 'lte', 999999999))->toBeTrue()
        ->and($check('customer_id', 'lte', 0))->toBeFalse()
        ->and($check('priority', 'lte', 'abc'))->toBeFalse()
        ->and($check('support_team_id', 'is_null'))->toBeTrue()
        ->and($check('priority', 'is_null'))->toBeFalse()
        ->and($check('priority', 'not_null'))->toBeTrue()
        ->and($check('support_team_id', 'not_null'))->toBeFalse()
        ->and($check('waiting_customer_hours', 'is_null'))->toBeTrue();
});

it('derives the ticket SLA state as breached, at risk or ok', function (): void {
    $evaluator = new SupportAutomationConditionEvaluator;
    $state = static fn (Ticket $ticket): bool => $evaluator->matches($ticket, [['field' => 'sla_state', 'operator' => 'in', 'value' => ['breached']]]);

    $breachedByFlag = Ticket::factory()->create(['response_breached' => true]);
    $breachedByClock = Ticket::factory()->create(['resolution_due_at' => now()->subMinute(), 'resolved_at' => null]);
    $atRiskResponse = Ticket::factory()->create(['response_due_at' => now()->addMinutes(30), 'first_response_at' => null]);
    $atRiskResolution = Ticket::factory()->create(['resolution_due_at' => now()->addHours(2), 'resolved_at' => null]);
    $healthy = Ticket::factory()->create(['response_due_at' => now()->addHours(5), 'resolution_due_at' => now()->addDay()]);

    $atRisk = static fn (Ticket $ticket): bool => $evaluator->matches($ticket, [['field' => 'sla_state', 'operator' => 'equals', 'value' => 'at_risk']]);

    expect($state($breachedByFlag))->toBeTrue()
        ->and($state($breachedByClock))->toBeTrue()
        ->and($atRisk($atRiskResponse))->toBeTrue()
        ->and($atRisk($atRiskResolution))->toBeTrue()
        ->and($atRisk($healthy))->toBeFalse()
        ->and($state($healthy))->toBeFalse();
});

it('evaluates maintenance record conditions and rejects unsupported fields or subjects', function (): void {
    $record = MaintenanceRecord::factory()->covered()->create();
    $evaluator = new SupportAutomationConditionEvaluator;

    $matches = $evaluator->matches($record, [
        ['field' => 'status', 'operator' => 'equals', 'value' => $record->status->value],
        ['field' => 'customer_id', 'operator' => 'equals', 'value' => $record->customer_id],
        ['field' => 'warranty_status', 'operator' => 'equals', 'value' => 'covered'],
        ['field' => 'age_hours', 'operator' => 'lte', 'value' => 1],
    ]);

    expect($matches)->toBeTrue()
        ->and(fn (): bool => $evaluator->matches($record, [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]))
        ->toThrow(DomainException::class, 'Unsupported maintenance automation condition field: priority')
        ->and(fn (): bool => $evaluator->matches(User::factory()->create(), [['field' => 'status', 'operator' => 'equals', 'value' => 'x']]))
        ->toThrow(DomainException::class, 'Unsupported automation subject type');
});

// ---------------------------------------------------------------------------
// Action executor
// ---------------------------------------------------------------------------

it('refuses to run an action against a subject that is not a ticket', function (): void {
    $record = MaintenanceRecord::factory()->create();

    expect(fn () => app(SupportAutomationActionExecutor::class)->execute($record, ['type' => 'auto_assign']))
        ->toThrow(DomainException::class, 'requires a ticket subject');
});

it('auto-assigns the least loaded eligible member of the ticket team', function (): void {
    $team = coverageAutomationTeam('AUTO');

    $busy = coverageAutomationAgent();
    $free = coverageAutomationAgent();
    $inactiveEmployee = coverageAutomationAgent();
    $inactiveEmployee->update(['is_active' => false]);

    $deleted = coverageAutomationAgent();
    $remoteOnly = coverageAutomationAgent();
    $full = coverageAutomationAgent();

    $team->members()->create(['employee_id' => $busy->id, 'routing_weight' => 100, 'is_active' => true]);
    $team->members()->create(['employee_id' => $free->id, 'routing_weight' => 100, 'is_active' => true]);
    $team->members()->create(['employee_id' => $inactiveEmployee->id, 'is_active' => true]);
    $team->members()->create(['employee_id' => $deleted->id, 'is_active' => true]);
    $team->members()->create(['employee_id' => $remoteOnly->id, 'is_active' => true, 'accepts_onsite' => false]);
    $team->members()->create(['employee_id' => $full->id, 'is_active' => true, 'capacity' => 1]);
    $deleted->delete();

    Ticket::factory()->count(2)->create(['assigned_employee_id' => $busy->id, 'status' => TicketStatus::InProgress]);
    Ticket::factory()->create(['assigned_employee_id' => $full->id, 'status' => TicketStatus::InProgress]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'service_path' => TicketServicePath::OnSiteVisit,
        'support_team_id' => $team->id,
    ]);

    $result = app(SupportAutomationActionExecutor::class)->execute($ticket, ['type' => 'auto_assign']);

    $assignment = $ticket->assignments()->latest('id')->firstOrFail();

    expect($result)->toBe('Auto-assigned to '.$free->user->name)
        ->and($ticket->refresh()->assigned_employee_id)->toBe($free->id)
        ->and($ticket->status)->toBe(TicketStatus::Assigned)
        ->and($assignment->assignment_source)->toBe(TicketAssignmentSource::Automation)
        ->and($assignment->reason)->toBe('Assigned by support automation.');
});

it('honours the remote path preference and routing weight when auto-assigning', function (): void {
    $team = coverageAutomationTeam('REMOTE');
    $onsiteOnly = coverageAutomationAgent();
    $normal = coverageAutomationAgent();
    $preferred = coverageAutomationAgent();

    $team->members()->create(['employee_id' => $onsiteOnly->id, 'is_active' => true, 'accepts_remote' => false]);
    $team->members()->create(['employee_id' => $normal->id, 'is_active' => true, 'routing_weight' => 100]);
    $team->members()->create(['employee_id' => $preferred->id, 'is_active' => true, 'routing_weight' => 300]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'service_path' => TicketServicePath::RemoteSupport,
        'support_team_id' => $team->id,
    ]);

    app(SupportAutomationActionExecutor::class)->execute($ticket, ['type' => 'auto_assign']);

    expect($ticket->refresh()->assigned_employee_id)->toBe($preferred->id);
});

it('rejects auto-assignment without an active team or an eligible member', function (): void {
    $executor = app(SupportAutomationActionExecutor::class);

    $teamless = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $inactiveTeam = coverageAutomationTeam('DORMANT', false);
    $dormant = Ticket::factory()->create(['status' => TicketStatus::Live, 'support_team_id' => $inactiveTeam->id]);

    $emptyTeam = coverageAutomationTeam('EMPTY');
    $empty = Ticket::factory()->create(['status' => TicketStatus::Live, 'support_team_id' => $emptyTeam->id]);

    expect(fn () => $executor->execute($teamless, ['type' => 'auto_assign']))
        ->toThrow(DomainException::class, 'requires an active support team')
        ->and(fn () => $executor->execute($dormant, ['type' => 'auto_assign']))
        ->toThrow(DomainException::class, 'requires an active support team')
        ->and(fn () => $executor->execute($empty, ['type' => 'auto_assign']))
        ->toThrow(DomainException::class, 'No eligible support team member');
});

it('posts an internal note as the configured user', function (): void {
    $manager = coverageAutomationManager();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $executor = app(SupportAutomationActionExecutor::class);

    $result = $executor->execute($ticket, ['type' => 'post_internal_note', 'message' => '  Escalated by rule  ', 'user_id' => $manager->id]);

    $message = $ticket->messages()->sole();

    expect($result)->toBe('Posted internal note')
        ->and($message->message)->toBe('Escalated by rule')
        ->and($message->is_internal_note)->toBeTrue()
        ->and($message->sender_user_id)->toBe($manager->id)
        ->and($message->source_channel)->toBe('automation')
        ->and(fn () => $executor->execute($ticket, ['type' => 'post_internal_note', 'message' => '   ', 'user_id' => $manager->id]))
        ->toThrow(DomainException::class, 'requires a message and user_id')
        ->and(fn () => $executor->execute($ticket, ['type' => 'post_internal_note', 'message' => 'Hi']))
        ->toThrow(DomainException::class, 'requires a message and user_id');
});

it('adds a follower once and rejects a missing user reference', function (): void {
    $user = User::factory()->create(['name' => 'Follower Fiona']);
    $ticket = Ticket::factory()->create();
    $executor = app(SupportAutomationActionExecutor::class);

    $first = $executor->execute($ticket, ['type' => 'add_follower', 'user_id' => $user->id]);
    $second = $executor->execute($ticket, ['type' => 'add_follower', 'value' => (string) $user->id]);

    expect($first)->toBe('Added follower Follower Fiona')
        ->and($second)->toBe('Added follower Follower Fiona')
        ->and(CollaborationFollower::query()->where('subject_id', $ticket->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(fn () => $executor->execute($ticket, ['type' => 'add_follower']))
        ->toThrow(DomainException::class, 'requires a user_id');
});

it('creates an internal follow-up task with optional assignee and due date', function (): void {
    $author = User::factory()->create();
    $assignee = User::factory()->create();
    $ticket = Ticket::factory()->create();
    $executor = app(SupportAutomationActionExecutor::class);

    $full = $executor->execute($ticket, [
        'type' => 'create_follow_up',
        'author_id' => $author->id,
        'body' => '  Call the customer back  ',
        'assignee_id' => $assignee->id,
        'due_hours' => 0,
    ]);
    $minimal = $executor->execute($ticket, ['type' => 'create_follow_up', 'author_id' => (string) $author->id, 'value' => 'Plain follow-up']);

    $withDue = CollaborationEntry::query()->where('body', 'Call the customer back')->sole();
    $withoutDue = CollaborationEntry::query()->where('body', 'Plain follow-up')->sole();

    expect($full)->toBe('Created follow-up task #'.$withDue->id)
        ->and($minimal)->toBe('Created follow-up task #'.$withoutDue->id)
        ->and($withDue->assignee_id)->toBe($assignee->id)
        ->and($withDue->author_id)->toBe($author->id)
        ->and($withDue->type)->toBe('task')
        ->and($withDue->visibility)->toBe('internal')
        ->and($withDue->due_at?->isFuture())->toBeTrue()
        ->and($withDue->subject_id)->toBe($ticket->id)
        ->and($withoutDue->assignee_id)->toBeNull()
        ->and($withoutDue->due_at)->toBeNull()
        ->and(fn () => $executor->execute($ticket, ['type' => 'create_follow_up', 'body' => 'No author']))
        ->toThrow(DomainException::class, 'requires author_id and body')
        ->and(fn () => $executor->execute($ticket, ['type' => 'create_follow_up', 'author_id' => $author->id, 'body' => '']))
        ->toThrow(DomainException::class, 'requires author_id and body');
});

// ---------------------------------------------------------------------------
// Engine and assignment guard rails
// ---------------------------------------------------------------------------

it('skips malformed action entries but still runs the valid ones', function (): void {
    $team = coverageAutomationTeam('MALFORMED');
    $rule = SupportAutomationRule::query()->create([
        'name' => 'Mixed actions',
        'event_key' => SupportAutomationEvent::TicketCreated,
        'precedence' => 1,
        'is_active' => true,
        'conditions' => [],
        'actions' => ['not-an-action', ['type' => 'assign_team', 'team_id' => $team->id]],
    ]);

    $ticket = Ticket::factory()->create();

    app(SupportAutomationEngine::class)->handle(SupportAutomationEvent::TicketCreated, $ticket, [], 'event-malformed-action');

    $run = SupportAutomationRun::query()->where('support_automation_rule_id', $rule->id)->sole();

    expect($run->status)->toBe(SupportAutomationRunStatus::Succeeded)
        ->and($run->actions_executed)->toBe(['Assigned team '.$team->name])
        ->and($ticket->refresh()->support_team_id)->toBe($team->id);
});

it('requires an authenticated actor for manual ticket assignment', function (): void {
    $employee = coverageAutomationAgent();
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);

    expect(fn () => app(TicketAssignmentService::class)->assign($ticket, $employee, null))
        ->toThrow(DomainException::class, 'Manual ticket assignment requires an authenticated actor.')
        ->and($ticket->refresh()->assigned_employee_id)->toBeNull()
        ->and($ticket->assignments()->count())->toBe(0);
});

it('auto-assigns tickets that have no path-specific preference to any member with capacity', function (): void {
    $team = coverageAutomationTeam('ANYPATH');
    $employee = coverageAutomationAgent();
    $team->members()->create(['employee_id' => $employee->id, 'is_active' => true, 'accepts_onsite' => false, 'accepts_remote' => false]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'service_path' => TicketServicePath::Maintenance,
        'support_team_id' => $team->id,
    ]);

    app(SupportAutomationActionExecutor::class)->execute($ticket, ['type' => 'auto_assign']);

    expect($ticket->refresh()->assigned_employee_id)->toBe($employee->id);
});
