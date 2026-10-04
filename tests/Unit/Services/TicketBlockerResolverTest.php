<?php

declare(strict_types=1);

use App\Enums\TicketBlocker;
use App\Enums\TicketServicePath;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\TicketBlockerResolver;

it('projects direct operational blockers without querying maintenance', function (TicketStatus $status, TicketBlocker $blocker): void {
    $ticket = new Ticket;
    $ticket->status = $status;

    if ($status === TicketStatus::Live) {
        $ticket->assigned_employee_id = null;
    }

    expect(app(TicketBlockerResolver::class)->resolve($ticket))->toBe($blocker);
})->with([
    [TicketStatus::Pending, TicketBlocker::TriageRequired],
    [TicketStatus::PendingPayment, TicketBlocker::DiagnosticPayment],
    [TicketStatus::Live, TicketBlocker::Assignment],
    [TicketStatus::WaitingCustomer, TicketBlocker::CustomerResponse],
    [TicketStatus::Cancelled, TicketBlocker::Cancelled],
]);

it('projects sla breach before maintenance blockers', function (): void {
    $ticket = new Ticket;
    $ticket->status = TicketStatus::InProgress;
    $ticket->response_breached = true;
    $ticket->setAttribute('has_active_maintenance', false);

    expect(app(TicketBlockerResolver::class)->resolve($ticket))->toBe(TicketBlocker::SlaBreach);
});

it('uses maintenance query projections for quotation and maintenance blockers', function (): void {
    $quoted = new Ticket;
    $quoted->status = TicketStatus::InProgress;
    $quoted->service_path = TicketServicePath::Maintenance;
    $quoted->setAttribute('has_active_maintenance', true);
    $quoted->setAttribute('has_maintenance_quotation_pending', true);

    $needsJob = new Ticket;
    $needsJob->status = TicketStatus::InProgress;
    $needsJob->service_path = TicketServicePath::OnSiteVisit;
    $needsJob->setAttribute('has_active_maintenance', false);
    $needsJob->setAttribute('has_maintenance_quotation_pending', false);

    $clear = new Ticket;
    $clear->status = TicketStatus::InProgress;
    $clear->service_path = TicketServicePath::RemoteSupport;
    $clear->setAttribute('has_active_maintenance', false);
    $clear->setAttribute('has_maintenance_quotation_pending', false);

    $resolver = app(TicketBlockerResolver::class);

    expect($resolver->resolve($quoted))->toBe(TicketBlocker::QuotationApproval)
        ->and($resolver->resolve($needsJob))->toBe(TicketBlocker::MaintenanceAction)
        ->and($resolver->resolve($clear))->toBe(TicketBlocker::None);
});

it('provides a visible label and color for every blocker', function (): void {
    foreach (TicketBlocker::cases() as $blocker) {
        expect($blocker->label())->not->toBe('')
            ->and($blocker->color())->toBeIn(['success', 'danger', 'info', 'warning']);
    }
});
