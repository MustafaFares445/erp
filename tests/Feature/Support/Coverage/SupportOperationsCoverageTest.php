<?php

declare(strict_types=1);

use App\Enums\SupportAssignmentStrategy;
use App\Enums\SupportAutomationEvent;
use App\Enums\SupportAutomationRunStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Filament\Resources\SupportAutomationRules\Pages\CreateSupportAutomationRule;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceTask;
use App\Models\ServiceAppointment;
use App\Models\SupportAutomationRule;
use App\Models\SupportAutomationRun;
use App\Models\SupportQueue;
use App\Models\SupportRoutingRule;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\TicketAssignment;
use App\Models\User;
use App\Services\Support\SupportQueueQueryService;
use App\Services\Support\TechnicianAvailabilityService;
use App\Services\Support\TicketRoutingService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function opsTeam(string $code, SupportAssignmentStrategy $strategy = SupportAssignmentStrategy::LeastLoaded, bool $active = true): SupportTeam
{
    return SupportTeam::query()->create([
        'code' => $code,
        'name' => $code.' team',
        'assignment_strategy' => $strategy,
        'default_capacity' => 10,
        'is_active' => $active,
    ]);
}

function opsAgent(): EmployeeProfile
{
    $user = User::factory()->admin()->create();
    $user->assignRole('Support Agent');

    return EmployeeProfile::factory()->create(['user_id' => $user->id, 'is_active' => true]);
}

function opsAppointment(EmployeeProfile $employee, string $status, int $startHour, int $endHour): ServiceAppointment
{
    return ServiceAppointment::query()->create([
        'maintenance_task_id' => MaintenanceTask::factory()->create()->id,
        'employee_id' => $employee->id,
        'status' => $status,
        'scheduled_start_at' => now()->startOfDay()->addDays(3)->addHours($startHour),
        'scheduled_end_at' => now()->startOfDay()->addDays(3)->addHours($endHour),
        'estimated_duration_minutes' => 60,
        'address_snapshot' => ['address' => 'Test address', 'city' => 'Aleppo'],
    ]);
}

// ---------------------------------------------------------------------------
// Create automation rule page
// ---------------------------------------------------------------------------

it('decodes JSON list condition values when a rule is created from the admin form', function (): void {
    config()->set('support.support_automation_enabled', true);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    Livewire::actingAs($manager)
        ->test(CreateSupportAutomationRule::class)
        ->fillForm([
            'name' => 'Escalate urgent or high',
            'event_key' => SupportAutomationEvent::TicketCreated->value,
            'precedence' => 5,
            'is_active' => true,
            'conditions' => [
                ['field' => 'priority', 'operator' => 'in', 'value' => ' ["urgent","high"] '],
                ['field' => 'status', 'operator' => 'equals', 'value' => 'live'],
                ['field' => 'type', 'operator' => 'in', 'value' => '[broken json'],
                ['field' => 'support_team_id', 'operator' => 'is_null', 'value' => null],
            ],
            'actions' => [['type' => 'auto_assign']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = SupportAutomationRule::query()->where('name', 'Escalate urgent or high')->sole();

    expect($rule->conditions)->toHaveCount(4)
        ->and($rule->conditions[0]['value'])->toBe(['urgent', 'high'])
        ->and($rule->conditions[1]['value'])->toBe('live')
        ->and($rule->conditions[2]['value'])->toBe('[broken json')
        ->and($rule->conditions[3]['value'] ?? null)->toBeNull();
});

it('passes form data through untouched when conditions are malformed or absent', function (): void {
    config()->set('support.support_automation_enabled', true);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $page = Livewire::actingAs($manager)->test(CreateSupportAutomationRule::class)->instance();
    $mutate = new ReflectionMethod($page, 'mutateFormDataBeforeCreate');

    $notAList = $mutate->invoke($page, ['name' => 'X', 'conditions' => 'not-a-list']);
    $absent = $mutate->invoke($page, ['name' => 'X']);
    $mixed = $mutate->invoke($page, ['conditions' => ['scalar', ['field' => 'priority', 'value' => 5], ['field' => 'priority', 'value' => '[1,2]']]]);

    expect($notAList)->toBe(['name' => 'X', 'conditions' => 'not-a-list'])
        ->and($absent)->toBe(['name' => 'X'])
        ->and($mixed['conditions'][0])->toBe('scalar')
        ->and($mixed['conditions'][1]['value'])->toBe(5)
        ->and($mixed['conditions'][2]['value'])->toBe([1, 2]);
});

// ---------------------------------------------------------------------------
// Scheduled commands
// ---------------------------------------------------------------------------

it('does not emit stale-customer events while automation is disabled', function (): void {
    config()->set('support.support_automation_enabled', false);

    Ticket::factory()->create([
        'status' => TicketStatus::WaitingCustomer,
        'waiting_customer_since' => now()->subDays(3),
    ]);

    $this->artisan('support:automation:stale')
        ->expectsOutputToContain('Support automation is disabled.')
        ->assertSuccessful();

    expect(SupportAutomationRun::query()->count())->toBe(0);
});

it('emits deterministic at-risk automation events for first response and resolution', function (): void {
    config()->set('support.support_automation_enabled', true);

    $team = opsTeam('RISK');
    $rule = SupportAutomationRule::query()->create([
        'name' => 'SLA risk escalation',
        'event_key' => SupportAutomationEvent::SlaAtRisk,
        'precedence' => 1,
        'is_active' => true,
        'conditions' => [],
        'actions' => [['type' => 'assign_team', 'team_id' => $team->id]],
    ]);

    $atRisk = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'live_at' => now()->subHour(),
        'response_due_at' => now()->addMinutes(30),
        'resolution_due_at' => now()->addHours(2),
    ]);
    $healthy = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'live_at' => now()->subHour(),
        'response_due_at' => now()->addHours(6),
        'resolution_due_at' => now()->addDays(2),
    ]);
    $answered = Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'live_at' => now()->subHour(),
        'first_response_at' => now()->subMinutes(10),
        'resolved_at' => now()->subMinutes(5),
        'response_due_at' => now()->addMinutes(30),
        'resolution_due_at' => now()->addHours(2),
    ]);
    $closed = Ticket::factory()->create([
        'status' => TicketStatus::Closed,
        'live_at' => now()->subHour(),
        'response_due_at' => now()->addMinutes(30),
        'resolution_due_at' => now()->addHours(2),
    ]);

    $this->artisan('support:sla:reconcile')
        ->expectsOutputToContain('Reconciled 3 support tickets.')
        ->assertSuccessful();

    $runs = SupportAutomationRun::query()->where('support_automation_rule_id', $rule->id)->get();
    $first = hash('sha256', 'sla-risk|'.$atRisk->id.'|first_response|'.$atRisk->response_due_at->timestamp);
    $second = hash('sha256', 'sla-risk|'.$atRisk->id.'|resolution|'.$atRisk->resolution_due_at->timestamp);

    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('event_uuid')->sort()->values()->all())->toBe(collect([$first, $second])->sort()->values()->all())
        ->and($runs->every(fn (SupportAutomationRun $run): bool => $run->status === SupportAutomationRunStatus::Succeeded && $run->subject_id === $atRisk->id))->toBeTrue()
        ->and($atRisk->refresh()->support_team_id)->toBe($team->id)
        ->and($healthy->refresh()->support_team_id)->toBeNull()
        ->and($answered->refresh()->support_team_id)->toBeNull()
        ->and($closed->refresh()->support_team_id)->toBeNull();

    // A repeat sweep must not create duplicate runs for the same deadlines.
    $this->artisan('support:sla:reconcile')->assertSuccessful();

    expect(SupportAutomationRun::query()->where('support_automation_rule_id', $rule->id)->count())->toBe(2);
});

it('does not emit at-risk events when automation is disabled', function (): void {
    config()->set('support.support_automation_enabled', false);

    Ticket::factory()->create([
        'status' => TicketStatus::Live,
        'live_at' => now()->subHour(),
        'response_due_at' => now()->addMinutes(30),
        'resolution_due_at' => now()->addHours(2),
    ]);

    $this->artisan('support:sla:reconcile')
        ->expectsOutputToContain('Reconciled 1 support tickets.')
        ->assertSuccessful();

    expect(SupportAutomationRun::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Queue criteria
// ---------------------------------------------------------------------------

it('applies team, path, waiting, age and SLA-risk queue criteria', function (): void {
    $team = opsTeam('QUEUES');
    $service = app(SupportQueueQueryService::class);

    $ids = static fn (SupportQueue $queue): array => $service->query($queue)->orderBy('id')->pluck('id')->all();
    $queue = static fn (array $criteria, ?SupportTeam $forTeam = null): SupportQueue => SupportQueue::query()->create([
        'name' => 'Queue '.fake()->unique()->numerify('####'),
        'is_active' => true,
        'support_team_id' => $forTeam?->id,
        'criteria' => $criteria,
    ]);

    $teamTicket = Ticket::factory()->create(['support_team_id' => $team->id, 'service_path' => TicketServicePath::RemoteSupport]);
    $onsite = Ticket::factory()->create(['service_path' => TicketServicePath::OnSiteVisit, 'assigned_employee_id' => opsAgent()->id]);
    $stale = Ticket::factory()->create([
        'status' => TicketStatus::WaitingCustomer,
        'waiting_customer_since' => now()->subHours(30),
    ]);
    $freshWaiting = Ticket::factory()->create([
        'status' => TicketStatus::WaitingCustomer,
        'waiting_customer_since' => now()->subHours(2),
    ]);
    $old = Ticket::factory()->create(['priority' => TicketPriority::Low]);
    $old->forceFill(['created_at' => now()->subDays(3)])->save();

    $responseBreached = Ticket::factory()->create(['response_due_at' => now()->subHour(), 'first_response_at' => null]);
    $resolutionBreached = Ticket::factory()->create(['resolution_due_at' => now()->subHour(), 'resolved_at' => null]);
    $responseSoon = Ticket::factory()->create(['response_due_at' => now()->addMinutes(20), 'first_response_at' => null]);
    $resolutionSoon = Ticket::factory()->create(['resolution_due_at' => now()->addHours(2), 'resolved_at' => null]);
    $comfortable = Ticket::factory()->create(['response_due_at' => now()->addHours(8), 'resolution_due_at' => now()->addDays(2)]);

    expect($ids($queue([], $team)))->toBe([$teamTicket->id])
        ->and($ids($queue(['service_paths' => ['on_site_visit', 7]])))->toBe([$onsite->id])
        ->and($ids($queue(['service_paths' => 'on_site_visit'])))->toBe([])
        ->and($ids($queue(['waiting_customer_hours' => 24])))->toBe([$stale->id])
        ->and($ids($queue(['waiting_customer_hours' => 'soon'])))->toContain($stale->id, $freshWaiting->id, $comfortable->id)
        ->and($ids($queue(['older_than_hours' => 48])))->toBe([$old->id])
        ->and($ids($queue(['older_than_hours' => 'abc'])))->toContain($old->id, $onsite->id)
        ->and($ids($queue(['unassigned' => false])))->toContain($onsite->id, $teamTicket->id)
        ->and($ids($queue(['sla_risk' => false])))->toContain($comfortable->id, $responseBreached->id)
        ->and($ids($queue(['sla_risk' => true])))->toBe([
            $responseBreached->id,
            $resolutionBreached->id,
            $responseSoon->id,
            $resolutionSoon->id,
        ]);
});

// ---------------------------------------------------------------------------
// Technician availability
// ---------------------------------------------------------------------------

it('lists only active technicians without an overlapping open appointment', function (): void {
    $service = app(TechnicianAvailabilityService::class);
    $start = now()->startOfDay()->addDays(3)->addHours(9);
    $end = now()->startOfDay()->addDays(3)->addHours(11);

    $free = EmployeeProfile::factory()->create(['is_active' => true]);
    $booked = EmployeeProfile::factory()->create(['is_active' => true]);
    $cancelledOnly = EmployeeProfile::factory()->create(['is_active' => true]);
    $completedOnly = EmployeeProfile::factory()->create(['is_active' => true]);
    $otherTime = EmployeeProfile::factory()->create(['is_active' => true]);
    $inactive = EmployeeProfile::factory()->create(['is_active' => false]);

    opsAppointment($booked, 'planned', 10, 12);
    opsAppointment($cancelledOnly, 'cancelled', 9, 11);
    opsAppointment($completedOnly, 'completed', 9, 11);
    opsAppointment($otherTime, 'planned', 14, 16);

    $available = $service->availableEmployeeIds($start, $end);

    expect($available)->toContain($free->id, $cancelledOnly->id, $completedOnly->id, $otherTime->id)
        ->not->toContain($booked->id)
        ->not->toContain($inactive->id)
        ->and($service->hasConflict($booked, $start, $end))->toBeTrue()
        ->and($service->hasConflict($cancelledOnly, $start, $end))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Routing edge cases
// ---------------------------------------------------------------------------

it('round-robins to the member who was assigned the longest ago and skips unusable members', function (): void {
    $team = opsTeam('RR', SupportAssignmentStrategy::RoundRobin);
    $recent = opsAgent();
    $longAgo = opsAgent();
    $never = opsAgent();
    $removed = opsAgent();

    foreach ([$recent, $longAgo, $never, $removed] as $employee) {
        $team->members()->create(['employee_id' => $employee->id, 'is_active' => true]);
    }
    $removed->delete();

    foreach ([[$recent, now()->subHour()], [$longAgo, now()->subDays(5)]] as [$employee, $at]) {
        TicketAssignment::query()->create([
            'ticket_id' => Ticket::factory()->create()->id,
            'employee_id' => $employee->id,
            'assigned_at' => $at,
            'assignment_source' => 'manual',
            'support_team_id' => $team->id,
        ]);
    }

    SupportRoutingRule::query()->create([
        'name' => 'Round robin all',
        'precedence' => 1,
        'is_active' => true,
        'support_team_id' => $team->id,
        'auto_assign' => true,
    ]);

    $first = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $second = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $router = app(TicketRoutingService::class);

    // Never-assigned members rank before everyone with an assignment history.
    expect($router->route($first)?->employee?->is($never))->toBeTrue()
        ->and($first->refresh()->assigned_employee_id)->toBe($never->id)
        ->and($router->route($second)?->employee?->is($longAgo))->toBeTrue();
});

it('leaves a ticket unrouted when the matched team goes inactive before routing completes', function (): void {
    $team = opsTeam('RACE');
    SupportRoutingRule::query()->create([
        'name' => 'Race rule',
        'precedence' => 1,
        'is_active' => true,
        'support_team_id' => $team->id,
        'auto_assign' => true,
    ]);
    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);

    // Simulate a concurrent deactivation that lands after the rule query matched the team.
    $armed = true;
    SupportRoutingRule::retrieved(function () use (&$armed, $team): void {
        if ($armed) {
            $armed = false;
            SupportTeam::query()->whereKey($team->id)->update(['is_active' => false]);
        }
    });

    $decision = app(TicketRoutingService::class)->route($ticket);
    $armed = false;

    expect($decision)->toBeNull()
        ->and($ticket->refresh()->support_team_id)->toBeNull()
        ->and($ticket->routed_by_rule_id)->toBeNull();
});
