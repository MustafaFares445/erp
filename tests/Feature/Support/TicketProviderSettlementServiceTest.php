<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\TicketStatus;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Support\TicketProviderSettlementService;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();

    $this->app->instance(StripeClientInterface::class, new FakeStripeClient);
});

function providerSettledTicketLink(): TicketPaymentLink
{
    $ticket = Ticket::factory()->chargeable()->create();

    return TicketPaymentLink::factory()->for($ticket)->create(['amount' => '75.00', 'currency' => 'USD']);
}

it('settles a chargeable ticket from a verified Stripe transaction, reusing the same lifecycle rules a dashboard settlement uses', function (): void {
    $link = providerSettledTicketLink();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $link->ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
        'payment_intent_id' => 'pi_ticket_settle',
    ]);

    $result = app(TicketProviderSettlementService::class)->settle($transaction);

    expect($result->isSettled())->toBeTrue()
        ->and($link->refresh()->status)->toBe(PaymentLinkStatus::Settled)
        ->and($link->payment_method_reference)->toBe('pi_ticket_settle')
        ->and($link->ticket->refresh()->status)->toBe(TicketStatus::Live)
        ->and($link->ticket->live_at)->not->toBeNull();
});

it('settles using the narrowly-permissioned system-integration actor, never a real admin', function (): void {
    $link = providerSettledTicketLink();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $link->ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    app(TicketProviderSettlementService::class)->settle($transaction);

    expect($link->refresh()->settledBy?->email)->toBe('system-integration@ierp.internal');
});

it('records the settlement activity with the stripe source channel, distinct from a dashboard settlement', function (): void {
    $link = providerSettledTicketLink();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $link->ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    app(TicketProviderSettlementService::class)->settle($transaction);

    $activity = Activity::query()->where('description', 'support.payment_link.settled')->latest('id')->first();

    expect($activity?->getProperty('source_channel'))->toBe('stripe');
});

it('is idempotent when a transaction is settled twice', function (): void {
    $link = providerSettledTicketLink();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $link->ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    $service = app(TicketProviderSettlementService::class);
    $service->settle($transaction);
    $service->settle($transaction->refresh());

    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Settled);
});

it('is a no-op, without throwing, when the ticket has already moved past pending_payment through another path', function (): void {
    $link = providerSettledTicketLink();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $link->ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    // Simulate the ticket having been moved to Live by a concurrent path
    // without going through TicketPaymentService, so the link itself is
    // still Pending and the transaction is not yet considered settled.
    $link->ticket->forceFill(['status' => TicketStatus::Live])->saveQuietly();

    $result = app(TicketProviderSettlementService::class)->settle($transaction);

    expect($result->isSettled())->toBeFalse()
        ->and($link->refresh()->status)->toBe(PaymentLinkStatus::Pending);
});

it('refuses a transaction that has not succeeded with the provider yet', function (): void {
    $link = providerSettledTicketLink();
    $transaction = PaymentTransaction::factory()->create([
        'status' => PaymentTransactionStatus::Pending,
        'customer_id' => $link->ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    expect(fn () => app(TicketProviderSettlementService::class)->settle($transaction))
        ->toThrow(DomainException::class);
});

it('refuses a transaction whose purpose is not a chargeable ticket', function (): void {
    $order = Order::factory()->create();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
    ]);

    expect(fn () => app(TicketProviderSettlementService::class)->settle($transaction))
        ->toThrow(DomainException::class);
});
