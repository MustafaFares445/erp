<?php

declare(strict_types=1);

use App\Enums\SerializedCustodyType;
use App\Enums\TicketCustomerImpact;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\SerializedInventoryUnit;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Database\Seeders\SlaPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('support.customer_support_api_enabled', true);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.sla_v2_enabled', true);
    config()->set('support.customer_api_redirect_hosts', ['customer.example.test']);
    (new SlaPolicySeeder)->run();
});

function apiCustomer(): CustomerProfile
{
    return CustomerProfile::factory()->create();
}

it('creates and lists support tickets only for the authenticated customer', function (): void {
    $customer = apiCustomer();
    $user = $customer->user;
    Sanctum::actingAs($user, ['customer:*']);

    $response = $this->postJson('/api/customer/support/tickets', [
        'type' => TicketType::HardwareIssue->value,
        'customer_impact' => TicketCustomerImpact::Degraded->value,
        'title' => 'Printer jams after warm-up',
        'description' => 'The printer works for a few minutes and then jams.',
        'external_equipment_name' => 'External Printer',
        'external_serial_number' => 'EXT-123',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.stage', 'received')
        ->assertJsonPath('data.action_required', false);

    $ticket = Ticket::query()->where('customer_id', $customer->getKey())->firstOrFail();

    expect($ticket->created_by)->toBe($user->getKey())
        ->and($ticket->status)->toBe(TicketStatus::Pending);

    Ticket::factory()->create();

    $this->getJson('/api/customer/support/tickets')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ticket->getKey());
});

it('denies cross-customer ticket, equipment and maintenance access', function (): void {
    $customer = apiCustomer();
    $other = apiCustomer();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $otherTicket = Ticket::factory()->create(['customer_id' => $other->getKey()]);

    $this->getJson('/api/customer/support/tickets/'.$otherTicket->getKey())
        ->assertNotFound();

    $otherEquipment = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->getKey(),
    ]);

    $this->getJson('/api/customer/equipment/'.$otherEquipment->getKey())
        ->assertNotFound();
});

it('rejects creating a ticket against equipment owned by another customer', function (): void {
    $customer = apiCustomer();
    $other = apiCustomer();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $equipment = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $other->getKey(),
    ]);

    $this->postJson('/api/customer/support/tickets', [
        'type' => TicketType::HardwareIssue->value,
        'title' => 'Wrong asset',
        'description' => 'Trying to reference an asset from another account.',
        'serialized_inventory_unit_id' => $equipment->getKey(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('serialized_inventory_unit_id');
});

it('returns only public messages and lets the customer reply without consuming first response', function (): void {
    $customer = apiCustomer();
    $user = $customer->user;
    Sanctum::actingAs($user, ['customer:*']);

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::Live,
        'first_response_at' => null,
    ]);
    $support = User::factory()->admin()->create();

    TicketMessage::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'sender_user_id' => $support->getKey(),
        'message' => 'Public support reply',
        'is_internal_note' => false,
        'source_channel' => 'dashboard',
    ]);
    TicketMessage::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'sender_user_id' => $support->getKey(),
        'message' => 'Secret internal note',
        'is_internal_note' => true,
        'source_channel' => 'dashboard',
    ]);

    $this->getJson('/api/customer/support/tickets/'.$ticket->getKey().'/messages')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.message', 'Public support reply')
        ->assertJsonMissing(['message' => 'Secret internal note']);

    $this->postJson('/api/customer/support/tickets/'.$ticket->getKey().'/messages', [
        'message' => 'Customer follow-up',
    ])
        ->assertCreated()
        ->assertJsonPath('data.sender.side', 'customer');

    expect($ticket->refresh()->first_response_at)->toBeNull();

    $created = TicketMessage::query()->latest('id')->firstOrFail();

    expect($created->is_internal_note)->toBeFalse()
        ->and($created->source_channel)->toBe('customer_app');
});

it('creates a server-derived Stripe checkout session and reports payment state', function (): void {
    $customer = apiCustomer();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $ticket = Ticket::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => TicketStatus::PendingPayment,
    ]);
    $link = TicketPaymentLink::factory()->for($ticket)->create([
        'amount' => 75,
        'currency' => 'AED',
    ]);

    $response = $this->postJson('/api/customer/support/tickets/'.$ticket->getKey().'/payment-session', [
        'success_url' => 'https://customer.example.test/support/payment/success',
        'cancel_url' => 'https://customer.example.test/support/payment/cancel',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('amount_minor', 7500)
        ->assertJsonPath('currency', 'AED');

    expect((string) $response->json('checkout_url'))->toStartWith('https://checkout.stripe.test/')
        ->and($link->refresh()->payment_url)->toBe($response->json('checkout_url'));

    $this->getJson('/api/customer/support/tickets/'.$ticket->getKey().'/payment-status')
        ->assertOk()
        ->assertJsonPath('required', true)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('checkout_url', $response->json('checkout_url'));
});

it('hides the customer support API when its rollout switch is disabled', function (): void {
    config()->set('support.customer_support_api_enabled', false);

    $customer = apiCustomer();
    Sanctum::actingAs($customer->user, ['customer:*']);

    $this->getJson('/api/customer/support/tickets')->assertNotFound();
});
