<?php

declare(strict_types=1);

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventKey;
use App\Enums\TicketStatus;
use App\Events\MaintenanceRecordBilled;
use App\Events\SlaAtRisk;
use App\Events\TicketClosed;
use App\Events\TicketUpdated;
use App\Listeners\SendBusinessNotification;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\MaintenanceRecord;
use App\Models\NotificationDelivery;
use App\Models\SupportTeam;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    (new NotificationTemplateSeeder)->run();
});

it('requests customer feedback through the canonical notification dispatcher after closure', function (): void {
    config()->set('support.csat_enabled', true);

    $customer = CustomerProfile::factory()->create();
    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Closed,
        'closed_at' => now(),
    ]);

    app(SendBusinessNotification::class)->handle(new TicketClosed($ticket));

    $deliveries = NotificationDelivery::query()
        ->where('template_key', NotificationEventKey::TicketFeedbackRequested->value)
        ->where('subject_document_type', $ticket->getMorphClass())
        ->where('subject_document_id', $ticket->getKey())
        ->get();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('channel')->all())->toContain(
            NotificationChannel::Database,
            NotificationChannel::Mail,
        );
});

it('notifies the customer when a maintenance record is billed', function (): void {
    $customer = CustomerProfile::factory()->create();
    $record = MaintenanceRecord::factory()->create([
        'customer_id' => $customer->getKey(),
    ]);

    app(SendBusinessNotification::class)->handle(new MaintenanceRecordBilled($record));

    expect(NotificationDelivery::query()
        ->where('template_key', NotificationEventKey::MaintenanceRecordBilled->value)
        ->where('subject_document_type', $record->getMorphClass())
        ->where('subject_document_id', $record->getKey())
        ->count())->toBe(2);
});

it('targets SLA alerts to the assigned agent and team manager before using broad fallback recipients', function (): void {
    $manager = User::factory()->admin()->create();
    $agentUser = User::factory()->employee()->create();
    $agent = EmployeeProfile::factory()->create(['user_id' => $agentUser->getKey()]);
    $unrelatedAdmin = User::factory()->admin()->create();

    $team = SupportTeam::query()->create([
        'code' => 'SLA-OPS',
        'name' => 'SLA Operations',
        'manager_user_id' => $manager->getKey(),
    ]);

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::InProgress,
        'support_team_id' => $team->getKey(),
        'assigned_employee_id' => $agent->getKey(),
    ]);

    app(SendBusinessNotification::class)->handle(new SlaAtRisk($ticket, 'response'));

    $recipientIds = NotificationDelivery::query()
        ->where('template_key', NotificationEventKey::SlaAtRisk->value)
        ->where('notifiable_type', User::class)
        ->pluck('notifiable_id')
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($recipientIds)->toBe(collect([
        $manager->getKey(),
        $agentUser->getKey(),
    ])->sort()->values()->all())
        ->and($recipientIds)->not->toContain($unrelatedAdmin->getKey());
});

it('sends no feedback request while CSAT is disabled', function (): void {
    config()->set('support.csat_enabled', false);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed, 'closed_at' => now()]);

    app(SendBusinessNotification::class)->handle(new TicketClosed($ticket));

    expect(NotificationDelivery::query()->where('template_key', NotificationEventKey::TicketFeedbackRequested->value)->count())->toBe(0);
});

it('does not send both a status update and a feedback request when a ticket closes', function (): void {
    config()->set('support.csat_enabled', true);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Closed, 'closed_at' => now()]);

    app(SendBusinessNotification::class)->handle(new TicketUpdated($ticket));
    app(SendBusinessNotification::class)->handle(new TicketClosed($ticket));

    expect(NotificationDelivery::query()->where('template_key', NotificationEventKey::TicketUpdated->value)->count())->toBe(0)
        ->and(NotificationDelivery::query()->where('template_key', NotificationEventKey::TicketFeedbackRequested->value)->count())->toBeGreaterThan(0);

    config()->set('support.csat_enabled', false);
    app(SendBusinessNotification::class)->handle(new TicketUpdated($ticket));

    expect(NotificationDelivery::query()->where('template_key', NotificationEventKey::TicketUpdated->value)->count())->toBeGreaterThan(0);
});

it('notifies the billed customer and nobody else about a maintenance invoice', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $record = MaintenanceRecord::factory()->create(['customer_id' => $customer->getKey()]);

    app(SendBusinessNotification::class)->handle(new MaintenanceRecordBilled($record));

    $deliveries = NotificationDelivery::query()->where('template_key', NotificationEventKey::MaintenanceRecordBilled->value)->get();

    expect($deliveries)->not->toBeEmpty()
        ->and($deliveries->where('notifiable_type', User::class)->where('notifiable_id', $other->user_id))->toBeEmpty()
        ->and($deliveries->where('notifiable_type', CustomerProfile::class)->where('notifiable_id', $other->getKey()))->toBeEmpty();
});

it('falls back to ticket managers for SLA alerts only when nobody is responsible, and never duplicates a person', function (): void {
    (new SupportPermissionSeeder)->run();

    $managerUser = User::factory()->admin()->create();
    $managerUser->assignRole('Support Manager');

    $plainAdmin = User::factory()->admin()->create();

    $unassigned = Ticket::factory()->create(['status' => TicketStatus::Live, 'assigned_employee_id' => null, 'support_team_id' => null]);

    app(SendBusinessNotification::class)->handle(new SlaAtRisk($unassigned, 'resolution'));

    $fallback = NotificationDelivery::query()->where('template_key', NotificationEventKey::SlaAtRisk->value)->pluck('notifiable_id')->unique()->all();

    expect($fallback)->toContain($managerUser->getKey())->not->toContain($plainAdmin->getKey());

    $person = User::factory()->employee()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $person->getKey()]);
    $team = SupportTeam::query()->create(['code' => 'SELF', 'name' => 'Self', 'manager_user_id' => $person->getKey()]);
    $both = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'assigned_employee_id' => $employee->getKey(), 'support_team_id' => $team->getKey()]);

    NotificationDelivery::query()->delete();
    app(SendBusinessNotification::class)->handle(new SlaAtRisk($both, 'response'));

    expect(NotificationDelivery::query()->where('notifiable_id', $person->getKey())->where('channel', NotificationChannel::Database)->count())->toBe(1);
});
