<?php

declare(strict_types=1);

use App\Enums\TicketCustomerImpact;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\PaymentTransaction;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the customer-reported impact separately from the internal priority', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create([
        'customer_impact' => TicketCustomerImpact::Degraded,
    ]);

    Livewire::actingAs($admin)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSee('Customer-reported impact')
        ->assertSee(TicketCustomerImpact::Degraded->label())
        ->assertSee('Internal priority');
});

it('shows the Stripe provider status on a ticket settled through a provider transaction', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->settled()->for($ticket)->create();
    PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
    ]);

    Livewire::actingAs($admin)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSee('Provider status');
});

it('shows no provider transaction for a ticket with no payment link at all', function (): void {
    $admin = User::factory()->admin()->create();
    $ticket = Ticket::factory()->create();

    Livewire::actingAs($admin)
        ->test(ViewTicket::class, ['record' => $ticket->getRouteKey()])
        ->assertSee('No provider transaction');
});
