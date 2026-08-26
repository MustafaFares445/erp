<?php

declare(strict_types=1);

use App\Enums\MaintenanceStatus;
use App\Enums\SupportPermission;
use App\Enums\TicketStatus;
use App\Filament\Pages\SupportDashboard;
use App\Filament\Widgets\SupportStatistics;
use App\Filament\Widgets\SupportTicketTrend;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('gates the ticket trend widget on the ticket view permission', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(SupportTicketTrend::canView())->toBeFalse();

    $user->givePermissionTo(SupportPermission::TicketView->value);

    expect(SupportTicketTrend::canView())->toBeTrue();
});

it('reports open ticket, SLA breach, pending maintenance, and monthly service record counts', function (): void {
    // Open tickets: everything except resolved/closed/cancelled.
    Ticket::factory()->create(['status' => TicketStatus::Pending]);
    Ticket::factory()->create(['status' => TicketStatus::Live]);
    Ticket::factory()->create(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);
    Ticket::factory()->create(['status' => TicketStatus::Closed, 'resolved_at' => now()]);
    Ticket::factory()->create(['status' => TicketStatus::Cancelled]);

    // Ticket::scopeResolutionBreached() ORs the stored flag with a live
    // due-date check, so setting the flag directly is the simplest way to
    // make it true without needing to also model SLA due dates here.
    Ticket::factory()->create(['status' => TicketStatus::InProgress, 'resolution_breached' => true]);

    MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);
    MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Closed]);

    MaintenanceTask::factory()->create(['created_at' => now()]);
    MaintenanceTask::factory()->create(['created_at' => now()]);
    MaintenanceTask::factory()->create(['created_at' => now()->subMonths(2)]);

    $widget = app(SupportStatistics::class);
    $stats = new ReflectionMethod($widget, 'getStats')->invoke($widget);
    $values = array_map(fn ($stat) => $stat->getValue(), $stats);

    expect($values)->toBe([3, 1, 2, 2]);
});

it('uses a line chart for the ticket trend', function (): void {
    $widget = app(SupportTicketTrend::class);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line');
});

it('buckets opened and resolved ticket counts by month over the trailing six months', function (): void {
    $currentMonthStart = now()->startOfMonth();
    $twoMonthsAgo = $currentMonthStart->copy()->subMonths(2);

    Ticket::factory()->create(['created_at' => $currentMonthStart->copy()->addDays(2)]);
    Ticket::factory()->create(['created_at' => $currentMonthStart->copy()->addDays(5)]);
    Ticket::factory()->create([
        'created_at' => $twoMonthsAgo->copy()->addDays(3),
        'status' => TicketStatus::Resolved,
        'resolved_at' => $currentMonthStart->copy()->addDay(),
    ]);

    $widget = app(SupportTicketTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect($data['labels'])->toHaveCount(6)
        ->and($data['labels'][5])->toBe($currentMonthStart->format('M Y'))
        ->and($data['labels'][3])->toBe($twoMonthsAgo->format('M Y'))
        ->and($data['datasets'][0]['label'])->toBe('Opened')
        ->and($data['datasets'][0]['data'][5])->toBe(2)
        ->and($data['datasets'][0]['data'][3])->toBe(1)
        ->and($data['datasets'][1]['label'])->toBe('Resolved')
        ->and($data['datasets'][1]['data'][5])->toBe(1)
        ->and($data['datasets'][1]['data'][3])->toBe(0);
});
