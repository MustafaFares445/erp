<?php

declare(strict_types=1);

use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;

/**
 * The transition matrix from data-model.md §8, asserted exhaustively rather
 * than by sampling: every ordered pair of statuses is checked, so a case added
 * later without a matrix entry fails here instead of silently permitting or
 * forbidding a transition nobody looked at.
 */
$legalTransitions = [
    'draft' => ['pending_approval', 'approved', 'cancelled'],
    'pending_approval' => ['approved', 'rejected', 'cancelled'],
    'rejected' => ['draft', 'cancelled'],
    'approved' => ['sent', 'cancelled'],
    'sent' => ['partially_received', 'received', 'closed', 'cancelled'],
    'partially_received' => ['received', 'closed'],
    'received' => [],
    'closed' => [],
    'cancelled' => [],
];

describe('PurchaseOrderStatus', function () use ($legalTransitions): void {
    it('has the nine documented cases in lifecycle order', function (): void {
        expect(PurchaseOrderStatus::values())->toBe([
            'draft',
            'pending_approval',
            'approved',
            'rejected',
            'sent',
            'partially_received',
            'received',
            'closed',
            'cancelled',
        ]);
    });

    it('permits exactly the documented transitions and refuses every other pair', function () use ($legalTransitions): void {
        foreach (PurchaseOrderStatus::cases() as $from) {
            foreach (PurchaseOrderStatus::cases() as $to) {
                $shouldBeLegal = in_array($to->value, $legalTransitions[$from->value], true);

                expect($from->canTransitionTo($to))->toBe(
                    $shouldBeLegal,
                    sprintf('%s -> %s', $from->value, $to->value),
                );
            }
        }
    });

    it('refuses every transition out of a terminal status, including to itself', function (): void {
        foreach ([PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled] as $terminal) {
            expect($terminal->isTerminal())->toBeTrue();

            foreach (PurchaseOrderStatus::cases() as $target) {
                expect($terminal->canTransitionTo($target))->toBeFalse();
            }
        }
    });

    it('treats only sent and partially received as receivable (FR-036)', function (): void {
        foreach (PurchaseOrderStatus::cases() as $status) {
            $expected = in_array($status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartiallyReceived], true);

            expect($status->isReceivable())->toBe($expected, $status->value);
        }
    });

    it('treats only draft as editable, so approval freezes the figures before transmission does (FR-025)', function (): void {
        foreach (PurchaseOrderStatus::cases() as $status) {
            expect($status->isEditable())->toBe($status === PurchaseOrderStatus::Draft, $status->value);
        }
    });

    it('gives a partially received order no cancellation path, because a receipt has already completed against it', function (): void {
        // FR-026's rule is enforced twice: the service refuses cancellation once
        // any receipt is complete, and the matrix removes the target entirely for
        // the one status that cannot have been reached without one.
        expect(PurchaseOrderStatus::PartiallyReceived->canTransitionTo(PurchaseOrderStatus::Cancelled))->toBeFalse()
            ->and(PurchaseOrderStatus::Sent->canTransitionTo(PurchaseOrderStatus::Cancelled))->toBeTrue();
    });

    it('lets a rejected order return to draft so the buyer can revise it', function (): void {
        expect(PurchaseOrderStatus::Rejected->canTransitionTo(PurchaseOrderStatus::Draft))->toBeTrue();
    });
});

describe('SupplierConfirmationStatus', function (): void {
    it('has pending, partial, confirmed, and rejected states', function (): void {
        expect(SupplierConfirmationStatus::values())->toBe(['pending', 'partial', 'confirmed', 'rejected']);
    });

    it('answers only once — an answered confirmation can move nowhere (FR-031)', function (): void {
        expect(SupplierConfirmationStatus::Pending->canTransitionTo(SupplierConfirmationStatus::Confirmed))->toBeTrue()
            ->and(SupplierConfirmationStatus::Pending->canTransitionTo(SupplierConfirmationStatus::Rejected))->toBeTrue()
            ->and(SupplierConfirmationStatus::Pending->canTransitionTo(SupplierConfirmationStatus::Pending))->toBeFalse();

        foreach ([SupplierConfirmationStatus::Confirmed, SupplierConfirmationStatus::Rejected] as $answered) {
            foreach (SupplierConfirmationStatus::cases() as $target) {
                expect($answered->canTransitionTo($target))->toBeFalse();
            }
        }
    });

    it('reports pending as unanswered and both outcomes as answered', function (): void {
        expect(SupplierConfirmationStatus::Pending->isAnswered())->toBeFalse()
            ->and(SupplierConfirmationStatus::Confirmed->isAnswered())->toBeTrue()
            ->and(SupplierConfirmationStatus::Rejected->isAnswered())->toBeTrue();
    });
});
