<?php

declare(strict_types=1);

use App\Enums\SlaMilestoneKey;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Events\SlaAtRisk;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Services\Support\SlaPolicyResolver;
use App\Services\Support\SlaService;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('starts active assignment and onsite SLA milestones for an onsite live ticket', function (): void {
    config()->set('support.sla_v2_enabled', true);
    (new SlaPolicySeeder)->run();

    $ticket = Ticket::withoutEvents(fn (): Ticket => Ticket::factory()->withPriority(TicketPriority::Normal)->create([
        'status' => TicketStatus::Pending,
        'service_path' => TicketServicePath::OnSiteVisit,
        'live_at' => null,
        'response_due_at' => null,
    ]));

    $policy = app(SlaPolicyResolver::class)->resolve($ticket)->policy;

    foreach ([
        [SlaMilestoneKey::Assignment, 30, 30],
        [SlaMilestoneKey::OnsiteArrival, 120, 40],
    ] as [$key, $minutes, $order]) {
        $policy->milestones()->updateOrCreate(
            ['key' => $key->value],
            [
                'target_minutes' => $minutes,
                'at_risk_before_minutes' => 10,
                'pause_when_waiting_customer' => false,
                'is_active' => true,
                'sort_order' => $order,
            ],
        );
    }

    $optionalKeys = $policy->refresh()->milestones
        ->pluck('key')
        ->map(static fn (SlaMilestoneKey $key): string => $key->value)
        ->all();
    expect($optionalKeys)
        ->toContain(SlaMilestoneKey::Assignment->value)
        ->toContain(SlaMilestoneKey::OnsiteArrival->value);

    $service = app(SlaService::class);
    $service->onTicketCreated($ticket);
    $ticket->forceFill(['status' => TicketStatus::Live, 'live_at' => null])->saveQuietly();
    $beforeLive = $ticket->refresh();
    expect(app(SlaPolicyResolver::class)->resolve($beforeLive)->policy->id)->toBe($policy->id)
        ->and($beforeLive->service_path)->toBe(TicketServicePath::OnSiteVisit)
        ->and($beforeLive->live_at)->toBeNull();

    $service->onTicketLive($beforeLive);

    $keys = $ticket->refresh()->slaMilestones
        ->pluck('key')
        ->map(static fn (SlaMilestoneKey $key): string => $key->value)
        ->all();

    expect($keys)
        ->toContain(SlaMilestoneKey::Assignment->value)
        ->toContain(SlaMilestoneKey::OnsiteArrival->value);
});

it('marks both legacy SLA clocks breached and dispatches the combined risk event', function (): void {
    config()->set('support.sla_v2_enabled', false);
    Event::fake([SlaAtRisk::class]);

    $ticket = Ticket::factory()->withPriority(TicketPriority::High)->create([
        'response_due_at' => now()->subMinutes(5),
        'resolution_due_at' => now()->subMinute(),
        'first_response_at' => null,
        'resolved_at' => null,
        'response_breached' => false,
        'resolution_breached' => false,
    ]);

    app(SlaService::class)->refreshBreachFlags($ticket);

    expect($ticket->refresh()->response_breached)->toBeTrue()
        ->and($ticket->resolution_breached)->toBeTrue();

    Event::assertDispatched(
        SlaAtRisk::class,
        fn (SlaAtRisk $event): bool => $event->ticket->is($ticket)
            && $event->kind === 'response+resolution',
    );
});
