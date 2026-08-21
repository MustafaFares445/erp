<?php

declare(strict_types=1);

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Resources\SlaPolicies\Pages\EditSlaPolicy;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Policies\SlaPolicyPolicy;
use App\Services\Support\SlaService;
use App\Services\Support\TicketIntakeService;
use App\Services\Support\TicketLifecycleService;
use App\Services\Support\TicketPaymentService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();
});

function makeSlaSupportManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    return $manager;
}

it('starts the sla clock only at live, snapshotting the priority targets in force at that moment', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Pending]);

    expect($ticket->live_at)->toBeNull();

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    $ticket->refresh();

    expect($ticket->live_at)->not->toBeNull()
        ->and($ticket->sla_response_target_minutes)->toBe(60)
        ->and($ticket->sla_resolution_target_minutes)->toBe(240)
        ->and((int) abs($ticket->response_due_at?->diffInMinutes($ticket->live_at) ?? 0))->toBe(60)
        ->and((int) abs($ticket->resolution_due_at?->diffInMinutes($ticket->live_at) ?? 0))->toBe(240);
});

it('accrues no sla time on a pending_payment ticket before settlement', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->withPriority(TicketPriority::Urgent)->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create();

    $this->travel(3)->hours();

    app(TicketPaymentService::class)->settle($link, 'REF-SLA', $admin);
    $ticket->refresh();

    expect($ticket->live_at)->not->toBeNull()
        ->and(abs($ticket->live_at->diffInSeconds(now())))->toBeLessThan(2)
        ->and((int) abs($ticket->response_due_at?->diffInMinutes($ticket->live_at) ?? 0))->toBe(60);
});

it('sets response and resolution breach flags once due times pass, sticky through later events', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);

    $this->travel(5)->hours();

    app(SlaService::class)->refreshBreachFlags($ticket->refresh());
    $ticket->refresh();

    expect($ticket->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();

    // Sticky: a later priority change never clears an already-set flag.
    app(TicketIntakeService::class)->update($ticket, ['priority' => TicketPriority::Low->value], $manager);
    $ticket->refresh();

    expect($ticket->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();
});

it('flags breached tickets via the scheduled reconcile command, registered on the schedule, idempotently', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);

    $this->travel(5)->hours();

    $this->artisan('support:sla:reconcile')
        ->expectsOutputToContain('Reconciled 1 support tickets.')
        ->assertSuccessful();

    expect($ticket->refresh()->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();

    $this->artisan('support:sla:reconcile')->assertSuccessful();
    $this->artisan('schedule:list')
        ->expectsOutputToContain('support:sla:reconcile')
        ->assertSuccessful();
});

it('suspends the resolution clock in waiting_customer and extends resolution_due_at by the paused duration on resume', function (): void {
    $manager = makeSlaSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $employeeProfile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    app(TicketLifecycleService::class)->assign($ticket, $employeeProfile, $manager);
    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

    $ticket->refresh();
    $resolutionDueBefore = $ticket->resolution_due_at;

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::WaitingCustomer, $agent);
    $ticket->refresh();

    expect($ticket->waiting_customer_since)->not->toBeNull();

    $this->travel(30)->minutes();

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::InProgress, $agent);
    $ticket->refresh();

    $extensionSeconds = abs($ticket->resolution_due_at?->diffInSeconds($resolutionDueBefore) ?? 0);

    expect($ticket->waiting_customer_since)->toBeNull()
        ->and($ticket->waiting_customer_accumulated_seconds)->toBeGreaterThanOrEqual(1800)
        ->and($extensionSeconds)->toBeGreaterThanOrEqual(1800)
        ->and($ticket->resolution_due_at?->greaterThan($resolutionDueBefore))->toBeTrue();
});

it('preserves a completed waiting_customer extension when the priority changes afterward', function (): void {
    $manager = makeSlaSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $employeeProfile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    app(TicketLifecycleService::class)->assign($ticket, $employeeProfile, $manager);
    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::WaitingCustomer, $agent);
    $this->travel(30)->minutes();
    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

    $ticket->refresh();
    $accumulatedSeconds = $ticket->waiting_customer_accumulated_seconds;
    $resolutionDueAfterPause = $ticket->resolution_due_at;

    expect($accumulatedSeconds)->toBeGreaterThanOrEqual(1800);

    app(TicketIntakeService::class)->update($ticket, ['priority' => TicketPriority::Urgent->value], $manager);
    $ticket->refresh();

    // The bug this guards against: recomputing resolution_due_at as a bare live_at + target
    // would silently drop the extension already granted by the completed pause above.
    $expectedResolutionDueAt = $ticket->live_at?->clone()
        ->addMinutes($ticket->sla_resolution_target_minutes)
        ->addSeconds($accumulatedSeconds);

    expect($ticket->waiting_customer_accumulated_seconds)->toBe($accumulatedSeconds)
        ->and($ticket->resolution_due_at?->equalTo($expectedResolutionDueAt))->toBeTrue()
        ->and($ticket->resolution_due_at?->equalTo($resolutionDueAfterPause))->toBeFalse();
});

it('immediately re-flags resolution breach when a past-due resolved ticket is reopened, without waiting for the sweep', function (): void {
    $manager = makeSlaSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $employeeProfile = EmployeeProfile::factory()->create(['user_id' => $agent->id]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::Urgent)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    app(TicketLifecycleService::class)->assign($ticket, $employeeProfile, $manager);
    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

    $this->travel(5)->hours();

    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::Resolved, $agent);
    expect($ticket->refresh()->resolution_breached)->toBeFalse();

    app(TicketLifecycleService::class)->transition($ticket->refresh(), TicketStatus::InProgress, $agent);

    // No scheduled sweep (support:sla:reconcile) has run between reopen and this assertion.
    expect($ticket->refresh()->resolution_breached)->toBeTrue()
        ->and($ticket->resolved_at)->toBeNull();
});

it('recomputes due times from the original live_at on a priority change, audits it, and flags an immediate breach if already past due', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Low)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    $ticket->refresh();
    $liveAt = $ticket->live_at;

    $this->travel(2)->hours();

    app(TicketIntakeService::class)->update($ticket, ['priority' => TicketPriority::Urgent->value], $manager);
    $ticket->refresh();

    expect($ticket->priority)->toBe(TicketPriority::Urgent)
        ->and($ticket->sla_response_target_minutes)->toBe(60)
        ->and($ticket->response_due_at?->equalTo($liveAt?->clone()->addMinutes(60)))->toBeTrue()
        ->and($ticket->response_breached)->toBeTrue()
        ->and(AuditLog::query()->where('description', 'support.ticket.priority_changed')->where('subject_id', $ticket->id)->exists())->toBeTrue();
});

it('flags both response and resolution breach on a priority change when both due times have already passed', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Low)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);

    $this->travel(5)->hours();

    app(TicketIntakeService::class)->update($ticket, ['priority' => TicketPriority::Urgent->value], $manager);

    expect($ticket->refresh()->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();
});

it('does not restart an already-started clock when onTicketLive runs again', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    $originalLiveAt = $ticket->refresh()->live_at;

    app(SlaService::class)->onTicketLive($ticket);

    expect($ticket->refresh()->live_at?->equalTo($originalLiveAt))->toBeTrue();
});

it('does nothing when resuming a ticket that was never paused', function (): void {
    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['waiting_customer_since' => null]);
    $resolutionDueBefore = $ticket->resolution_due_at;

    app(SlaService::class)->onResumeFromWaiting($ticket);

    expect($ticket->refresh()->resolution_due_at?->equalTo($resolutionDueBefore) ?? ($resolutionDueBefore === null))->toBeTrue()
        ->and($ticket->waiting_customer_since)->toBeNull();
});

it('never changes an already-started ticket due times when its SLA policy is edited afterward', function (): void {
    $manager = makeSlaSupportManager();
    $ticket = Ticket::factory()->withPriority(TicketPriority::Normal)->create(['status' => TicketStatus::Pending]);
    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Live, $manager);
    $ticket->refresh();
    $originalResponseDue = $ticket->response_due_at;

    SlaPolicy::query()->where('priority', TicketPriority::Normal)->update(['response_target_minutes' => 5]);

    expect($ticket->refresh()->response_due_at?->equalTo($originalResponseDue))->toBeTrue();
});

it('seeds exactly the four documented default sla policy rows, idempotently', function (): void {
    (new SlaPolicySeeder)->run();

    expect(SlaPolicy::query()->count())->toBe(4)
        ->and(SlaPolicy::query()->where('priority', TicketPriority::Urgent)->first())
        ->toMatchArray(['response_target_minutes' => 60, 'resolution_target_minutes' => 240])
        ->and(SlaPolicy::query()->where('priority', TicketPriority::High)->first())
        ->toMatchArray(['response_target_minutes' => 240, 'resolution_target_minutes' => 1440])
        ->and(SlaPolicy::query()->where('priority', TicketPriority::Normal)->first())
        ->toMatchArray(['response_target_minutes' => 480, 'resolution_target_minutes' => 2880])
        ->and(SlaPolicy::query()->where('priority', TicketPriority::Low)->first())
        ->toMatchArray(['response_target_minutes' => 1440, 'resolution_target_minutes' => 4320]);
});

it('lets a Support Manager edit sla policy but denies Support Agent and Reviewer', function (): void {
    $manager = makeSlaSupportManager();
    $agent = User::factory()->admin()->create();
    $agent->assignRole('Support Agent');

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    expect(app(SlaPolicyPolicy::class)->update($manager))->toBeTrue()
        ->and(app(SlaPolicyPolicy::class)->update($agent))->toBeFalse()
        ->and(app(SlaPolicyPolicy::class)->update($reviewer))->toBeFalse();
});

it('saves an edited sla policy through the actual Edit form and stamps updated_by', function (): void {
    $manager = makeSlaSupportManager();
    $policy = SlaPolicy::query()->where('priority', TicketPriority::Normal)->firstOrFail();

    Livewire::actingAs($manager)
        ->test(EditSlaPolicy::class, ['record' => $policy->getRouteKey()])
        ->assertFormFieldIsDisabled('priority')
        ->fillForm([
            'response_target_minutes' => 500,
            'resolution_target_minutes' => 3000,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($policy->refresh()->response_target_minutes)->toBe(500)
        ->and($policy->resolution_target_minutes)->toBe(3000)
        ->and($policy->priority)->toBe(TicketPriority::Normal)
        ->and($policy->updated_by)->toBe($manager->id);
});

it('never permits creating or bulk-deleting SLA policies, mirroring DashboardUserResource (4 fixed, seeded rows)', function (): void {
    expect(SlaPolicyResource::canCreate())->toBeFalse()
        ->and(SlaPolicyResource::canDeleteAny())->toBeFalse();
});

it('reports a stored breach flag as breached without consulting the clock', function (): void {
    // Once SlaService has stamped a breach, the flag is the answer: the due dates
    // may since have been reset, and a ticket that was late stays late.
    $breached = Ticket::factory()->create([
        'response_breached' => true,
        'resolution_breached' => true,
        'response_due_at' => now()->addDay(),
        'resolution_due_at' => now()->addDay(),
        'first_response_at' => now(),
    ]);

    expect($breached->isResponseBreached())->toBeTrue()
        ->and($breached->isResolutionBreached())->toBeTrue();

    // And with no flag and no elapsed deadline, neither reads breached.
    $onTime = Ticket::factory()->create([
        'response_breached' => false,
        'resolution_breached' => false,
        'response_due_at' => now()->addDay(),
        'resolution_due_at' => now()->addDay(),
        'first_response_at' => null,
    ]);

    expect($onTime->isResponseBreached())->toBeFalse()
        ->and($onTime->isResolutionBreached())->toBeFalse();
});
