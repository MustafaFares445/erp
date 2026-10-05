<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\EmployeeProfile;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

function coverage112Page(Ticket $ticket): ViewTicket
{
    $page = new ViewTicket;
    $page->record = $ticket;

    return $page;
}

it('covers assignment domain failure notification path', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Live]);
    $employee = EmployeeProfile::factory()->create(['is_active' => false]);

    $page = coverage112Page($ticket);
    $action = new ReflectionMethod(ViewTicket::class, 'makeAssignAction')->invoke($page);
    $closure = $action->getActionFunction();

    expect($closure)->toBeInstanceOf(Closure::class);

    $closure(['employee_id' => $employee->id]);

    expect($ticket->refresh()->assigned_employee_id)->toBeNull()
        ->and($ticket->status)->toBe(TicketStatus::Live);
});

it('covers settlement early return and domain failure notification path', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $withoutLink = Ticket::factory()->create(['status' => TicketStatus::PendingPayment]);
    $page = coverage112Page($withoutLink);
    $action = new ReflectionMethod(ViewTicket::class, 'makeSettlePaymentAction')->invoke($page);
    $closure = $action->getActionFunction();

    expect($closure)->toBeInstanceOf(Closure::class);
    $closure([
        'payment_method_reference' => 'REF-112',
        'payment_method_id' => 1,
    ]);

    expect($withoutLink->refresh()->status)->toBe(TicketStatus::PendingPayment);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::PendingPayment]);
    $link = TicketPaymentLink::factory()->for($ticket)->create([
        'status' => PaymentLinkStatus::Cancelled,
    ]);

    $page = coverage112Page($ticket->refresh());
    $action = new ReflectionMethod(ViewTicket::class, 'makeSettlePaymentAction')->invoke($page);
    $closure = $action->getActionFunction();

    $closure([
        'payment_method_reference' => 'REF-112-CANCELLED',
        'payment_method_id' => 999999,
    ]);

    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Cancelled)
        ->and($ticket->refresh()->status)->toBe(TicketStatus::PendingPayment);
});

it('covers invalid lifecycle transition notification path', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $ticket = Ticket::factory()->create(['status' => TicketStatus::Pending]);
    $page = coverage112Page($ticket);

    $action = new ReflectionMethod(ViewTicket::class, 'transitionAction')
        ->invoke($page, 'coverageClose', 'Close', TicketStatus::Closed);
    $closure = $action->getActionFunction();

    expect($closure)->toBeInstanceOf(Closure::class);
    $closure([]);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending);
});
