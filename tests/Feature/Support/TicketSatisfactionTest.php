<?php

declare(strict_types=1);

use App\Enums\TicketStatus;
use App\Events\TicketClosed;
use App\Models\CustomerProfile;
use App\Models\Ticket;
use App\Models\TicketSatisfactionResponse;
use App\Models\User;
use App\Services\Support\TicketLifecycleService;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.customer_support_api_enabled', true);
    config()->set('support.csat_enabled', true);
    config()->set('support.support_automation_enabled', false);
    (new SupportPermissionSeeder)->run();
});

it('accepts one customer satisfaction response after ticket closure', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Closed,
        'closed_at' => now(),
    ]);

    $this->postJson('/api/customer/support/tickets/'.$ticket->getKey().'/satisfaction', [
        'rating' => 5,
        'comment' => 'Fast and helpful.',
    ])
        ->assertCreated()
        ->assertJsonPath('ticket_id', $ticket->getKey())
        ->assertJsonPath('rating', 5);

    expect(TicketSatisfactionResponse::query()->where('ticket_id', $ticket->getKey())->count())->toBe(1);

    $this->postJson('/api/customer/support/tickets/'.$ticket->getKey().'/satisfaction', [
        'rating' => 4,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rating');
});

it('rejects feedback before closure and for another customer ticket', function (): void {
    $customer = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $open = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Live,
    ]);

    $this->postJson('/api/customer/support/tickets/'.$open->getKey().'/satisfaction', [
        'rating' => 5,
    ])->assertUnprocessable();

    $otherTicket = Ticket::factory()->create([
        'customer_id' => $other->getKey(),
        'status' => TicketStatus::Closed,
    ]);

    $this->postJson('/api/customer/support/tickets/'.$otherTicket->getKey().'/satisfaction', [
        'rating' => 5,
    ])->assertNotFound();
});

it('exposes feedback eligibility and the submitted rating on the customer ticket resource', function (): void {
    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Closed,
        'closed_at' => now(),
    ]);

    $this->getJson('/api/customer/support/tickets/'.$ticket->getKey())
        ->assertOk()
        ->assertJsonPath('data.feedback.eligible', true)
        ->assertJsonPath('data.feedback.submitted', false);

    TicketSatisfactionResponse::query()->create([
        'ticket_id' => $ticket->getKey(),
        'customer_id' => $customer->getKey(),
        'rating' => 4,
        'comment' => null,
        'submitted_at' => now(),
        'source_channel' => 'customer_app',
    ]);

    $this->getJson('/api/customer/support/tickets/'.$ticket->getKey())
        ->assertOk()
        ->assertJsonPath('data.feedback.eligible', false)
        ->assertJsonPath('data.feedback.submitted', true)
        ->assertJsonPath('data.feedback.rating', 4);
});

it('dispatches a dedicated close event when a resolved ticket is closed', function (): void {
    Event::fake([TicketClosed::class]);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $ticket = Ticket::factory()->create([
        'status' => TicketStatus::Resolved,
        'resolved_at' => now()->subMinute(),
    ]);

    app(TicketLifecycleService::class)->transition($ticket, TicketStatus::Closed, $manager);

    expect($ticket->refresh()->closed_at)->not->toBeNull();

    Event::assertDispatched(TicketClosed::class, static fn (TicketClosed $event): bool => $event->ticket->is($ticket));
});

it('hides satisfaction submission when the rollout switch is disabled', function (): void {
    config()->set('support.csat_enabled', false);

    $customer = CustomerProfile::factory()->create();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Closed,
    ]);

    $this->postJson('/api/customer/support/tickets/'.$ticket->getKey().'/satisfaction', [
        'rating' => 5,
    ])->assertNotFound();
});
