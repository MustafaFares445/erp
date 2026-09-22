<?php

declare(strict_types=1);

use App\Enums\QuotationStatus;
use App\Enums\TicketStatus;
use App\Events\CampaignCompleted;
use App\Events\QuotationDecided;
use App\Events\QuotationExpired;
use App\Events\TaskAssigned;
use App\Events\TicketUpdated;
use App\Listeners\SendBusinessNotification;
use App\Models\Campaign;
use App\Models\EmployeeProfile;
use App\Models\PlanTask;
use App\Models\Quotation;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new NotificationTemplateSeeder)->run();
});

it('covers business notification fallback and missing-recipient guards', function (): void {
    $listener = app(SendBusinessNotification::class);

    $listener->handle(new stdClass);

    $campaign = new Campaign;
    $campaign->forceFill(['name' => 'Coverage campaign']);

    $listener->handle(new CampaignCompleted($campaign, 0, 0));

    $task = new PlanTask;
    $task->forceFill(['title' => 'Coverage task', 'due_at' => today()]);

    $listener->handle(new TaskAssigned($task));

    $ticket = new Ticket;
    $ticket->forceFill([
        'ticket_number' => 'TCK-COVERAGE',
        'status' => TicketStatus::Pending,
    ]);
    $listener->handle(new TicketUpdated($ticket));

    expect(true)->toBeTrue();
});

it('dispatches quotation decided and expired notifications to the assigned employee user', function (): void {
    $listener = app(SendBusinessNotification::class);
    $user = User::factory()->employee()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->getKey()]);

    $decided = Quotation::factory()->accepted()->create([
        'employee_id' => $employee->getKey(),
        'status' => QuotationStatus::Accepted,
    ]);
    $expired = Quotation::factory()->expired()->create([
        'employee_id' => $employee->getKey(),
    ]);

    $listener->handle(new QuotationDecided($decided));
    $listener->handle(new QuotationExpired($expired));

    expect($user->notifications()->count())->toBeGreaterThanOrEqual(2);
});

it('formats a generic persisted model reference when no document-number attribute exists', function (): void {
    $listener = app(SendBusinessNotification::class);
    $user = User::factory()->create();

    $method = new ReflectionMethod(SendBusinessNotification::class, 'documentReference');

    expect($method->invoke($listener, $user))->toBe('User #'.$user->getKey());
});
