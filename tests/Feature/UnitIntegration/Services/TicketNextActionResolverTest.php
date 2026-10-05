<?php

declare(strict_types=1);

use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\TicketNextActionResolver;

it('returns the operational next action for each direct ticket state', function (TicketStatus $status, string $expected): void {
    $ticket = new Ticket;
    $ticket->status = $status;
    $ticket->setAttribute('has_active_maintenance', true);
    $ticket->setAttribute('has_maintenance_quotation_pending', false);

    if ($status === TicketStatus::Live) {
        $ticket->assigned_employee_id = null;
    }

    expect(app(TicketNextActionResolver::class)->resolve($ticket))->toBe($expected);
})->with([
    [TicketStatus::Pending, 'Triage equipment and choose the service path'],
    [TicketStatus::PendingPayment, 'Collect the diagnostic fee'],
    [TicketStatus::Live, 'Assign a support owner'],
    [TicketStatus::Assigned, 'Start support work'],
    [TicketStatus::InProgress, 'Continue support work'],
    [TicketStatus::WaitingCustomer, 'Follow up with the customer or wait for their reply'],
    [TicketStatus::Resolved, 'Review the resolution and close'],
    [TicketStatus::Closed, 'Complete'],
    [TicketStatus::Cancelled, 'No action — ticket cancelled'],
]);

it('surfaces maintenance and sla exceptions as the next action', function (): void {
    $maintenance = new Ticket;
    $maintenance->status = TicketStatus::InProgress;
    $maintenance->service_path = TicketServicePath::Maintenance;
    $maintenance->setAttribute('has_active_maintenance', false);
    $maintenance->setAttribute('has_maintenance_quotation_pending', false);

    $quoted = new Ticket;
    $quoted->status = TicketStatus::InProgress;
    $quoted->service_path = TicketServicePath::Maintenance;
    $quoted->setAttribute('has_active_maintenance', true);
    $quoted->setAttribute('has_maintenance_quotation_pending', true);

    $sla = new Ticket;
    $sla->status = TicketStatus::InProgress;
    $sla->response_breached = true;
    $sla->setAttribute('has_active_maintenance', true);

    $resolver = app(TicketNextActionResolver::class);

    expect($resolver->resolve($maintenance))->toBe('Raise the maintenance job')
        ->and($resolver->resolve($quoted))->toBe('Follow up on the repair quotation')
        ->and($resolver->resolve($sla))->toBe('Escalate this ticket and continue the current work');
});
