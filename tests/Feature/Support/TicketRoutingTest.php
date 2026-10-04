<?php

declare(strict_types=1);

use App\Enums\SupportAssignmentStrategy;
use App\Enums\TicketAssignmentSource;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\EmployeeProfile;
use App\Models\SupportRoutingRule;
use App\Models\SupportSkill;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketRoutingService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function routingAgent(string $name): array
{
    $user = User::factory()->admin()->create(['name' => $name]);
    $user->assignRole('Support Agent');

    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    return [$user, $employee];
}

it('routes to the matching team and least-loaded qualified employee', function (): void {
    [, $busy] = routingAgent('Busy agent');
    [, $free] = routingAgent('Free agent');

    $team = SupportTeam::query()->create([
        'code' => 'HW',
        'name' => 'Hardware',
        'assignment_strategy' => SupportAssignmentStrategy::LeastLoaded,
        'default_capacity' => 10,
        'is_active' => true,
    ]);

    $team->members()->create(['employee_id' => $busy->id, 'routing_weight' => 100, 'is_active' => true]);
    $team->members()->create(['employee_id' => $free->id, 'routing_weight' => 100, 'is_active' => true]);

    Ticket::factory()->count(2)->create([
        'assigned_employee_id' => $busy->id,
        'status' => TicketStatus::InProgress,
    ]);

    $rule = SupportRoutingRule::query()->create([
        'name' => 'All hardware',
        'precedence' => 10,
        'is_active' => true,
        'support_team_id' => $team->id,
        'auto_assign' => true,
    ]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'service_path' => TicketServicePath::Maintenance,
    ]);

    $decision = app(TicketRoutingService::class)->route($ticket);

    expect($decision)->not->toBeNull()
        ->and($decision?->team->is($team))->toBeTrue()
        ->and($decision?->employee?->is($free))->toBeTrue()
        ->and($ticket->refresh()->support_team_id)->toBe($team->id)
        ->and($ticket->assigned_employee_id)->toBe($free->id)
        ->and($ticket->routed_by_rule_id)->toBe($rule->id);

    $assignment = $ticket->assignments()->latest('id')->firstOrFail();

    expect($assignment->assignment_source)->toBe(TicketAssignmentSource::Routing)
        ->and($assignment->routing_rule_id)->toBe($rule->id)
        ->and($assignment->assigned_by)->toBeNull()
        ->and($assignment->reason)->toContain('Matched routing rule');
});

it('requires the configured skill and respects member capacity', function (): void {
    [, $skilledFull] = routingAgent('Skilled full');
    [, $unskilled] = routingAgent('Unskilled');
    [, $skilledFree] = routingAgent('Skilled free');

    $skill = SupportSkill::query()->create(['code' => 'PRINTER', 'name' => 'Printer', 'is_active' => true]);
    $skilledFull->supportSkills()->attach($skill->id, ['proficiency' => 90]);
    $skilledFree->supportSkills()->attach($skill->id, ['proficiency' => 80]);

    $team = SupportTeam::query()->create([
        'code' => 'FIELD',
        'name' => 'Field',
        'assignment_strategy' => SupportAssignmentStrategy::LeastLoaded,
        'default_capacity' => 5,
        'is_active' => true,
    ]);

    $team->members()->create(['employee_id' => $skilledFull->id, 'capacity' => 1, 'is_active' => true, 'accepts_onsite' => true]);
    $team->members()->create(['employee_id' => $unskilled->id, 'capacity' => 5, 'is_active' => true, 'accepts_onsite' => true]);
    $team->members()->create(['employee_id' => $skilledFree->id, 'capacity' => 5, 'is_active' => true, 'accepts_onsite' => true]);

    Ticket::factory()->create([
        'assigned_employee_id' => $skilledFull->id,
        'status' => TicketStatus::InProgress,
    ]);

    SupportRoutingRule::query()->create([
        'name' => 'Printer onsite',
        'precedence' => 5,
        'is_active' => true,
        'service_path' => TicketServicePath::OnSiteVisit,
        'support_team_id' => $team->id,
        'required_skill_id' => $skill->id,
        'auto_assign' => true,
    ]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'service_path' => TicketServicePath::OnSiteVisit,
    ]);

    expect(app(TicketRoutingService::class)->route($ticket)?->employee?->is($skilledFree))->toBeTrue();
});

it('routes pending-payment work to a team without assigning until the ticket is live', function (): void {
    [, $agent] = routingAgent('Queued agent');

    $team = SupportTeam::query()->create([
        'code' => 'QUEUE',
        'name' => 'Queue team',
        'assignment_strategy' => SupportAssignmentStrategy::LeastLoaded,
        'is_active' => true,
    ]);
    $team->members()->create(['employee_id' => $agent->id, 'is_active' => true]);

    SupportRoutingRule::query()->create([
        'name' => 'Queue all',
        'precedence' => 1,
        'is_active' => true,
        'support_team_id' => $team->id,
        'auto_assign' => true,
    ]);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingPayment]);

    $decision = app(TicketRoutingService::class)->route($ticket);

    expect($decision?->team->is($team))->toBeTrue()
        ->and($decision?->employee)->toBeNull()
        ->and($ticket->refresh()->support_team_id)->toBe($team->id)
        ->and($ticket->assigned_employee_id)->toBeNull();
});

it('uses routing weight as the deterministic least-load tie breaker', function (): void {
    [, $normal] = routingAgent('Normal weight');
    [, $preferred] = routingAgent('Preferred weight');

    $team = SupportTeam::query()->create([
        'code' => 'WEIGHT',
        'name' => 'Weighted',
        'assignment_strategy' => SupportAssignmentStrategy::LeastLoaded,
        'is_active' => true,
    ]);
    $team->members()->create(['employee_id' => $normal->id, 'routing_weight' => 100, 'is_active' => true]);
    $team->members()->create(['employee_id' => $preferred->id, 'routing_weight' => 200, 'is_active' => true]);

    SupportRoutingRule::query()->create([
        'name' => 'Weighted routing',
        'precedence' => 1,
        'is_active' => true,
        'support_team_id' => $team->id,
        'auto_assign' => true,
    ]);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);

    expect(app(TicketRoutingService::class)->route($ticket)?->employee?->is($preferred))->toBeTrue();
});

function routingTeam(string $code, bool $active = true, SupportAssignmentStrategy $strategy = SupportAssignmentStrategy::LeastLoaded): SupportTeam
{
    return SupportTeam::query()->create([
        'code' => $code,
        'name' => $code.' team',
        'assignment_strategy' => $strategy,
        'default_capacity' => 10,
        'is_active' => $active,
    ]);
}

function routingRule(SupportTeam $team, int $precedence, array $attributes = []): SupportRoutingRule
{
    return SupportRoutingRule::query()->create([
        'name' => 'Rule '.$team->code.' '.$precedence,
        'precedence' => $precedence,
        'is_active' => true,
        'support_team_id' => $team->id,
        'auto_assign' => true,
        ...$attributes,
    ]);
}

it('leaves a ticket unrouted when no rule matches', function (): void {
    $team = routingTeam('ONLY-HW');
    routingRule($team, 10, ['ticket_type' => 'hardware_issue']);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live, 'type' => 'general_support']);

    expect(app(TicketRoutingService::class)->route($ticket))->toBeNull()
        ->and($ticket->refresh()->support_team_id)->toBeNull()
        ->and($ticket->routed_by_rule_id)->toBeNull();
});

it('applies the lowest precedence matching rule and breaks ties by rule id', function (): void {
    $first = routingTeam('FIRST');
    $second = routingTeam('SECOND');
    $third = routingTeam('THIRD');

    routingRule($second, 50);
    $winner = routingRule($first, 5);
    routingRule($third, 5);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    $decision = app(TicketRoutingService::class)->route($ticket);

    expect($decision?->rule->is($winner))->toBeTrue()
        ->and($ticket->refresh()->support_team_id)->toBe($first->id);
});

it('ignores inactive rules and rules that point at an inactive team', function (): void {
    $inactiveTeam = routingTeam('DORMANT', false);
    $activeTeam = routingTeam('ACTIVE');

    routingRule($inactiveTeam, 1);
    routingRule($activeTeam, 2, ['is_active' => false]);
    $usable = routingRule($activeTeam, 3);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    expect(app(TicketRoutingService::class)->route($ticket)?->rule->is($usable))->toBeTrue();

    $usable->update(['is_active' => false]);
    $other = Ticket::factory()->create(['status' => TicketStatus::Pending]);

    expect(app(TicketRoutingService::class)->route($other))->toBeNull();
});

it('falls back to the team queue when no member is eligible', function (): void {
    [, $inactiveEmployee] = routingAgent('Inactive employee');
    $inactiveEmployee->update(['is_active' => false]);
    [, $inactiveMember] = routingAgent('Inactive member');
    [, $remoteOnly] = routingAgent('Remote only');

    $team = routingTeam('QUEUE');
    $team->members()->create(['employee_id' => $inactiveEmployee->id, 'is_active' => true]);
    $team->members()->create(['employee_id' => $inactiveMember->id, 'is_active' => false]);
    $team->members()->create(['employee_id' => $remoteOnly->id, 'is_active' => true, 'accepts_onsite' => false]);
    routingRule($team, 10);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live, 'service_path' => TicketServicePath::OnSiteVisit]);

    $decision = app(TicketRoutingService::class)->route($ticket);

    expect($decision?->employee)->toBeNull()
        ->and($decision?->reason)->toContain('team queue')
        ->and($ticket->refresh()->support_team_id)->toBe($team->id)
        ->and($ticket->assigned_employee_id)->toBeNull();
});

it('never auto-assigns for a manual-strategy team', function (): void {
    [, $employee] = routingAgent('Manual team member');
    $team = routingTeam('MANUAL', true, SupportAssignmentStrategy::Manual);
    $team->members()->create(['employee_id' => $employee->id, 'is_active' => true]);
    routingRule($team, 10);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);

    expect(app(TicketRoutingService::class)->route($ticket)?->employee)->toBeNull()
        ->and($ticket->refresh()->assigned_employee_id)->toBeNull();
});

it('keeps a manual assignment when the ticket is routed again', function (): void {
    [, $manual] = routingAgent('Manually assigned');
    [, $other] = routingAgent('Better candidate');

    $team = routingTeam('REROUTE');
    $team->members()->create(['employee_id' => $other->id, 'is_active' => true]);
    routingRule($team, 10);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'assigned_employee_id' => $manual->id]);

    $decision = app(TicketRoutingService::class)->route($ticket);

    expect($decision?->employee)->toBeNull()
        ->and($ticket->refresh()->assigned_employee_id)->toBe($manual->id)
        ->and($ticket->support_team_id)->toBe($team->id);
});
