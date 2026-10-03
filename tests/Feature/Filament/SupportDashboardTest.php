<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Filament\Pages\SupportDashboard;
use App\Filament\Widgets\SupportMaintenanceNeedsAttention;
use App\Filament\Widgets\SupportNeedsAttention;
use App\Filament\Widgets\SupportStatistics;
use App\Filament\Widgets\SupportTicketTrend;
use App\Filament\Widgets\SupportUpcomingMaintenance;
use App\Filament\Widgets\SupportWarrantyStatistics;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Support\TicketSlaStateResolver;
use Database\Seeders\SupportPermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
});

it('denies dashboard access without the ticket view permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(SupportDashboard::canAccess())->toBeFalse();
});

it('allows dashboard access with the ticket view permission', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(SupportPermission::TicketView->value);
    $this->actingAs($user);

    expect(SupportDashboard::canAccess())->toBeTrue();
});

it('gates the statistics widget on the ticket view permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(SupportStatistics::canView())->toBeFalse();

    $user->givePermissionTo(SupportPermission::TicketView->value);

    expect(SupportStatistics::canView())->toBeTrue();
});

it('gates warranty cost metrics on the maintenance cost view permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(SupportWarrantyStatistics::canView())->toBeFalse();

    $user->givePermissionTo(SupportPermission::MaintenanceCostView->value);

    expect(SupportWarrantyStatistics::canView())->toBeTrue();
});

it('gates the ticket trend widget on the ticket view permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(SupportTicketTrend::canView())->toBeFalse();

    $user->givePermissionTo(SupportPermission::TicketView->value);

    expect(SupportTicketTrend::canView())->toBeTrue();
});

it('reports tickets opened and resolved against the previous period, the open queue and SLA risk', function (): void {
    Ticket::factory()->create(['status' => TicketStatus::Pending]);
    Ticket::factory()->create(['status' => TicketStatus::Live]);
    Ticket::factory()->create(['status' => TicketStatus::WaitingCustomer]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'resolved_at' => now()->subDays(40), 'created_at' => now()->subDays(45)]);
    Ticket::factory()->create(['status' => TicketStatus::Cancelled]);

    // Ticket::scopeResolutionBreached() ORs the stored flag with a live
    // due-date check, so setting the flag directly is the simplest way to
    // make it true without needing to also model SLA due dates here.
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_breached' => true]);

    $stats = supportStats();

    expect($stats)->toHaveCount(4)
        ->and(array_map(fn (Stat $stat): mixed => $stat->getValue(), $stats))->toBe(['6', '1', '4', '1'])
        ->and((string) $stats[0]->getDescription())->toBe('500.0% increase')
        ->and((string) $stats[1]->getDescription())->toBe('No change vs previous period')
        ->and(array_sum($stats[0]->getChart() ?? []))->toBe(6)
        ->and((string) $stats[2]->getDescription())->toBe('1 waiting on the customer')
        ->and($stats[3]->getColor())->toBe('danger');
});

it('narrows ticket KPIs, the trend and the attention queue to the selected assignee and priority', function (): void {
    $assignee = EmployeeProfile::factory()->create();
    $mine = Ticket::factory()->create(['status' => TicketStatus::Pending, 'assigned_employee_id' => $assignee->id, 'priority' => TicketPriority::Urgent]);
    $theirs = Ticket::factory()->create(['status' => TicketStatus::Pending, 'priority' => TicketPriority::Low]);

    expect(supportStats(['assigneeId' => $assignee->id])[0]->getValue())->toBe('1')
        ->and(supportStats(['priority' => TicketPriority::Low->value])[0]->getValue())->toBe('1');

    $trend = app(SupportTicketTrend::class);
    $trend->pageFilters = ['priority' => TicketPriority::Urgent->value];

    expect(array_sum(new ReflectionMethod($trend, 'getData')->invoke($trend)['datasets'][0]['data']))->toBe(1);

    $this->actingAs(supportViewer(SupportPermission::TicketView));

    Livewire::test(SupportNeedsAttention::class, ['pageFilters' => ['assigneeId' => $assignee->id]])
        ->assertSee('Tickets needing attention')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('charts opened and resolved tickets across the selected period', function (): void {
    Ticket::factory()->count(2)->create(['created_at' => now()]);
    Ticket::factory()->create([
        'created_at' => now()->subDays(60),
        'status' => TicketStatus::Resolved,
        'resolved_at' => now(),
    ]);

    $widget = app(SupportTicketTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line')
        ->and($data['labels'])->toHaveCount(30)
        ->and($data['datasets'][0]['label'])->toBe('Opened')
        ->and(array_sum($data['datasets'][0]['data']))->toBe(2)
        ->and($data['datasets'][1]['label'])->toBe('Resolved')
        ->and(array_sum($data['datasets'][1]['data']))->toBe(1);
});

it('charts service economics in the default currency', function (): void {
    $widget = app(SupportWarrantyStatistics::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('bar')
        ->and($widget->getHeading())->toStartWith('Service economics (')
        ->and($data['labels'])->toHaveCount(5)
        ->and($data['datasets'][0]['data'])->toBe([0.0, 0.0, 0.0, 0.0, 0.0]);
});

it('lists maintenance needing action and upcoming maintenance', function (): void {
    $this->actingAs(supportViewer(SupportPermission::TicketView, SupportPermission::MaintenanceRequestView));

    $open = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    $closed = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Closed]);

    Livewire::test(SupportMaintenanceNeedsAttention::class)
        ->assertSee('Maintenance requiring action')
        ->assertSee('Record diagnosis')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$closed]);

    Livewire::test(SupportUpcomingMaintenance::class)
        ->assertSuccessful()
        ->assertSee('Upcoming maintenance');
});

it('lays out the support dashboard with paired rows and upcoming maintenance across the full width', function (): void {
    expect(new ReflectionMethod(SupportDashboard::class, 'getDashboardWidgets')->invoke(new SupportDashboard))->toBe([
        SupportStatistics::class,
        [SupportTicketTrend::class, SupportWarrantyStatistics::class],
        [SupportNeedsAttention::class, SupportMaintenanceNeedsAttention::class],
        SupportUpcomingMaintenance::class,
    ])
        ->and((new SupportUpcomingMaintenance)->getColumnSpan())->toBe('full');

    $this->actingAs(supportViewer(SupportPermission::TicketView));

    $resolved = new ReflectionMethod(SupportDashboard::class, 'resolveDashboardWidgets')->invoke(new SupportDashboard);

    expect($resolved[1])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($resolved[1]->widget)->toBe(SupportTicketTrend::class)
        ->and($resolved[2])->toBeInstanceOf(WidgetConfiguration::class)
        ->and($resolved[2]->widget)->toBe(SupportNeedsAttention::class);

    Livewire::test(SupportDashboard::class)
        ->assertSuccessful()
        ->assertSee('Assignee')
        ->assertSee('Priority');
});

/**
 * @param  array<string, mixed>  $filters
 * @return list<Stat>
 */
function supportStats(array $filters = []): array
{
    $widget = app(SupportStatistics::class);
    $widget->pageFilters = $filters;

    /** @var list<Stat> */
    return new ReflectionMethod($widget, 'getStats')->invoke($widget);
}

function supportViewer(SupportPermission ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(static fn (SupportPermission $permission): string => $permission->value, $permissions));

    return $user;
}

it('translates the SLA state label without changing its colour', function (): void {
    $resolver = app(TicketSlaStateResolver::class);
    $ticket = new Ticket;
    $ticket->forceFill(['status' => TicketStatus::Pending->value, 'live_at' => null]);

    app()->setLocale('ar');

    expect($resolver->label($ticket))->toBe(__('admin.support.sla_state.not_started'))
        ->and($resolver->label($ticket))->not->toBe('Not Started')
        ->and($resolver->color($ticket))->toBe('gray');
});
