<?php

declare(strict_types=1);

use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Support\TicketStageResolver;

it('projects every operational ticket status to a workspace stage', function (TicketStatus $status, TicketStage $stage): void {
    $ticket = new Ticket;
    $ticket->status = $status;

    expect(app(TicketStageResolver::class)->resolve($ticket))->toBe($stage);
})->with([
    [TicketStatus::Pending, TicketStage::Intake],
    [TicketStatus::PendingPayment, TicketStage::Triage],
    [TicketStatus::Live, TicketStage::ActiveSupport],
    [TicketStatus::Assigned, TicketStage::ActiveSupport],
    [TicketStatus::InProgress, TicketStage::ActiveSupport],
    [TicketStatus::WaitingCustomer, TicketStage::ActiveSupport],
    [TicketStatus::Resolved, TicketStage::Resolution],
    [TicketStatus::Closed, TicketStage::Closed],
    [TicketStatus::Cancelled, TicketStage::Closed],
]);

it('provides labels and colors for each workspace stage', function (): void {
    foreach (TicketStage::cases() as $stage) {
        expect($stage->label())->not->toBe('')
            ->and($stage->color())->toBeIn(['gray', 'warning', 'primary', 'success']);
    }
});
