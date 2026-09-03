<?php

declare(strict_types=1);

use App\Enums\WriteOffStatus;

$legalTransitions = [
    'draft' => ['approved', 'cancelled'],
    'approved' => [],
    'cancelled' => [],
];

describe('WriteOffStatus', function () use ($legalTransitions): void {
    it('permits exactly the approved lifecycle transitions', function () use ($legalTransitions): void {
        foreach (WriteOffStatus::cases() as $from) {
            foreach (WriteOffStatus::cases() as $to) {
                expect($from->canTransitionTo($to))->toBe(
                    in_array($to->value, $legalTransitions[$from->value], true),
                    sprintf('%s -> %s', $from->value, $to->value),
                );
            }
        }
    });

    it('marks approved and cancelled as terminal', function (): void {
        foreach (WriteOffStatus::cases() as $status) {
            expect($status->isTerminal())->toBe(
                in_array($status, [WriteOffStatus::Approved, WriteOffStatus::Cancelled], true),
                $status->value,
            );
        }
    });
});
