<?php

declare(strict_types=1);

use App\Enums\SlaMilestoneKey;
use App\Enums\TicketPriority;
use App\Enums\TicketServicePath;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\ProductVariant;
use App\Models\SlaCalendar;
use App\Models\SlaPolicy;
use App\Models\SupportServiceLevel;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\TicketSlaMilestone;
use App\Services\Support\SlaBusinessTimeCalculator;
use App\Services\Support\SlaMilestoneService;
use App\Services\Support\SlaPolicyResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param list<int> $weekdays */
function slaCoverageBusinessCalendar(array $weekdays, string $from = '09:00:00', string $to = '17:00:00'): SlaCalendar
{
    $calendar = SlaCalendar::query()->create([
        'name' => 'Business hours '.fake()->unique()->numerify('###'),
        'timezone' => 'UTC',
        'is_24x7' => false,
        'is_default' => false,
        'is_active' => true,
    ]);

    foreach ($weekdays as $weekday) {
        $calendar->periods()->create(['weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to]);
    }

    return $calendar;
}

it('rejects negative SLA targets', function (): void {
    $calendar = slaCoverageBusinessCalendar([1]);

    expect(fn () => app(SlaBusinessTimeCalculator::class)->addBusinessMinutes(
        CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'),
        -1,
        $calendar,
    ))->toThrow(DomainException::class, 'cannot be negative');
});

it('returns the start instant unchanged for a zero-minute target', function (): void {
    $calendar = slaCoverageBusinessCalendar([1]);
    $start = CarbonImmutable::parse('2026-10-05 20:15:00', 'UTC');

    $due = app(SlaBusinessTimeCalculator::class)->addBusinessMinutes($start, 0, $calendar);

    expect($due->equalTo($start))->toBeTrue();
});

it('skips working windows that have already ended and resumes in the next window of the day', function (): void {
    $calendar = SlaCalendar::query()->create([
        'name' => 'Split shift',
        'timezone' => 'UTC',
        'is_24x7' => false,
        'is_default' => false,
        'is_active' => true,
    ]);
    $calendar->periods()->create(['weekday' => 1, 'starts_at' => '09:00:00', 'ends_at' => '12:00:00']);
    $calendar->periods()->create(['weekday' => 1, 'starts_at' => '14:00:00', 'ends_at' => '17:00:00']);

    $due = app(SlaBusinessTimeCalculator::class)->addBusinessMinutes(
        CarbonImmutable::parse('2026-10-05 13:00:00', 'UTC'),
        60,
        $calendar,
    );

    expect($due->toDateTimeString())->toBe('2026-10-05 15:00:00');
});

it('treats a sub-minute remainder of a window as no capacity and rolls to the next working day', function (): void {
    $calendar = slaCoverageBusinessCalendar([1, 2]);

    $due = app(SlaBusinessTimeCalculator::class)->addBusinessMinutes(
        CarbonImmutable::parse('2026-10-05 16:59:30', 'UTC'),
        30,
        $calendar,
    );

    expect($due->toDateTimeString())->toBe('2026-10-06 09:30:00');
});

it('fails loudly when a business calendar has no working time to resolve a due date', function (): void {
    $calendar = slaCoverageBusinessCalendar([]);

    expect(fn () => app(SlaBusinessTimeCalculator::class)->addBusinessMinutes(
        CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'),
        30,
        $calendar,
    ))->toThrow(DomainException::class, 'Unable to resolve an SLA due time');
});

it('reports zero business minutes when the end does not follow the start', function (): void {
    $calendar = slaCoverageBusinessCalendar([1]);
    $at = CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC');
    $calculator = app(SlaBusinessTimeCalculator::class);

    expect($calculator->businessMinutesBetween($at, $at, $calendar))->toBe(0)
        ->and($calculator->businessMinutesBetween($at, $at->subHour(), $calendar))->toBe(0);
});

it('uses the reduced hours of a working-day exception instead of the weekly periods', function (): void {
    $calendar = slaCoverageBusinessCalendar([1, 2, 3, 4, 5]);
    $calendar->exceptions()->create([
        'date' => '2026-10-05',
        'name' => 'Half day',
        'is_working_day' => true,
        'starts_at' => '10:00:00',
        'ends_at' => '12:00:00',
    ]);

    $calculator = app(SlaBusinessTimeCalculator::class);
    $start = CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC');

    expect($calculator->addBusinessMinutes($start, 60, $calendar)->toDateTimeString())->toBe('2026-10-05 11:00:00')
        ->and($calculator->addBusinessMinutes($start, 180, $calendar)->toDateTimeString())->toBe('2026-10-06 10:00:00')
        ->and($calculator->businessMinutesBetween($start, $start->setTime(18, 0), $calendar))->toBe(120);
});

it('falls back to the weekly periods for a working-day exception without custom hours', function (): void {
    $calendar = slaCoverageBusinessCalendar([1]);
    $calendar->exceptions()->create([
        'date' => '2026-10-05',
        'name' => 'Normal day, flagged',
        'is_working_day' => true,
    ]);

    $minutes = app(SlaBusinessTimeCalculator::class)->businessMinutesBetween(
        CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-05 23:00:00', 'UTC'),
        $calendar,
    );

    expect($minutes)->toBe(480);
});

it('skips SLA policies whose scope does not match the ticket and resolves the matching default', function (): void {
    (new SlaPolicySeeder)->run();

    $default = SlaPolicy::query()->where('priority', TicketPriority::Normal->value)->orderBy('id')->firstOrFail();
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    $team = SupportTeam::query()->create(['code' => 'T1', 'name' => 'Team one', 'assignment_strategy' => 'manual', 'is_active' => true]);
    $level = SupportServiceLevel::query()->create(['code' => 'PLAT', 'name' => 'Platinum', 'is_active' => true]);
    $variant = ProductVariant::factory()->create();

    $mismatches = [
        'type' => ['ticket_type' => TicketType::HardwareIssue],
        'path' => ['service_path' => TicketServicePath::Maintenance],
        'team' => ['support_team_id' => $team->id],
        'customer' => ['customer_id' => $otherCustomer->id],
        'variant' => ['product_variant_id' => $variant->id],
        'level' => ['support_service_level_id' => $level->id],
    ];

    $created = [];
    foreach ($mismatches as $code => $scope) {
        $created[] = SlaPolicy::query()->create([
            'name' => 'Mismatch '.$code,
            'code' => 'mismatch-'.$code,
            'is_active' => true,
            'precedence' => 1,
            'sla_calendar_id' => $default->sla_calendar_id,
            'priority' => TicketPriority::Normal,
            'response_target_minutes' => 5,
            'resolution_target_minutes' => 10,
            ...$scope,
        ]);
    }

    $ticket = Ticket::factory()->for($customer, 'customer')->withPriority(TicketPriority::Normal)->create([
        'type' => TicketType::GeneralSupport,
    ]);

    $resolved = app(SlaPolicyResolver::class)->resolve($ticket);

    expect($resolved->policy->is($default))->toBeTrue()
        ->and($resolved->entitlement)->toBeNull()
        ->and($created)->toHaveCount(6);
});

it('does not start a milestone twice and keeps the originally snapshotted due time', function (): void {
    (new SlaPolicySeeder)->run();
    $policy = SlaPolicy::query()->where('priority', TicketPriority::Normal->value)->orderBy('id')->firstOrFail();
    $ticket = Ticket::factory()->create();
    $service = app(SlaMilestoneService::class);

    $first = $service->start($ticket, SlaMilestoneKey::FirstResponse, $policy, 60, CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    $second = $service->start($ticket, SlaMilestoneKey::FirstResponse, $policy, 600, CarbonImmutable::parse('2026-10-06 10:00:00', 'UTC'));

    expect($second->is($first))->toBeTrue()
        ->and($second->target_minutes)->toBe(60)
        ->and($second->due_at?->equalTo($first->due_at))->toBeTrue()
        ->and(TicketSlaMilestone::query()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

it('refuses to resume a paused milestone that has no business calendar', function (): void {
    $ticket = Ticket::factory()->create();

    TicketSlaMilestone::query()->create([
        'ticket_id' => $ticket->id,
        'key' => SlaMilestoneKey::Resolution->value,
        'target_minutes' => 60,
        'started_at' => now()->subHour(),
        'due_at' => now()->addHour(),
        'paused_at' => now()->subMinutes(10),
    ]);

    expect(fn () => app(SlaMilestoneService::class)->resume($ticket, SlaMilestoneKey::Resolution))
        ->toThrow(DomainException::class, 'cannot resume without its business calendar');

    expect($ticket->slaMilestones()->firstOrFail()->paused_at)->not->toBeNull();
});

it('fails to start a milestone when neither the policy nor the system has an active calendar', function (): void {
    SlaCalendar::query()->delete();
    $ticket = Ticket::factory()->create();

    expect(fn () => app(SlaMilestoneService::class)->start(
        $ticket,
        SlaMilestoneKey::FirstResponse,
        new SlaPolicy,
        30,
        CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'),
    ))->toThrow(DomainException::class, 'No active SLA calendar is configured.');

    expect(TicketSlaMilestone::query()->where('ticket_id', $ticket->id)->exists())->toBeFalse();
});
