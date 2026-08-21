<?php

declare(strict_types=1);

use App\Enums\TicketStatus;

it('allows exactly the FR-022 transitions and rejects everything else', function (): void {
    $expected = [
        TicketStatus::Pending->value => [TicketStatus::Live, TicketStatus::Cancelled],
        TicketStatus::PendingPayment->value => [TicketStatus::Live, TicketStatus::Cancelled],
        TicketStatus::Live->value => [TicketStatus::Assigned, TicketStatus::Cancelled],
        TicketStatus::Assigned->value => [TicketStatus::InProgress, TicketStatus::Live, TicketStatus::Cancelled],
        TicketStatus::InProgress->value => [TicketStatus::WaitingCustomer, TicketStatus::Resolved, TicketStatus::Assigned, TicketStatus::Cancelled],
        TicketStatus::WaitingCustomer->value => [TicketStatus::InProgress, TicketStatus::Resolved, TicketStatus::Cancelled],
        TicketStatus::Resolved->value => [TicketStatus::Closed, TicketStatus::InProgress],
        TicketStatus::Closed->value => [],
        TicketStatus::Cancelled->value => [],
    ];

    foreach (TicketStatus::cases() as $from) {
        $allowed = $expected[$from->value];

        foreach (TicketStatus::cases() as $to) {
            $shouldAllow = in_array($to, $allowed, true);

            expect($from->canTransitionTo($to))
                ->toBe($shouldAllow, sprintf('Expected %s -> %s to be ', $from->value, $to->value).($shouldAllow ? 'allowed' : 'rejected'));
        }
    }
});

it('treats closed and cancelled as terminal', function (): void {
    expect(TicketStatus::Closed->allowedTransitions())->toBe([])
        ->and(TicketStatus::Cancelled->allowedTransitions())->toBe([]);
});
