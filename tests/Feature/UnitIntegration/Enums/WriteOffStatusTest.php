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

    it('provides labels and colors for every state', function (): void {
        foreach (WriteOffStatus::cases() as $status) {
            expect($status->label())->toBeString()->not->toBe('')
                ->and($status->color())->toBeString()->not->toBe('');
        }
    });

    it('colours an approved write-off as a completed state rather than an error', function (): void {
        expect(WriteOffStatus::Approved->color())->toBe('success')
            ->and(WriteOffStatus::Draft->color())->toBe('gray')
            ->and(WriteOffStatus::Cancelled->color())->toBe('warning');
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
