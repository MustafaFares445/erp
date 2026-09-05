<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;

$legalTransitions = [
    'draft' => ['posted'],
    'posted' => ['reversed'],
    'reversed' => [],
];

describe('PaymentStatus', function () use ($legalTransitions): void {
    it('permits exactly the approved lifecycle transitions', function () use ($legalTransitions): void {
        foreach (PaymentStatus::cases() as $from) {
            foreach (PaymentStatus::cases() as $to) {
                $expected = in_array($to->value, $legalTransitions[$from->value], true);

                expect($from->canTransitionTo($to))->toBe(
                    $expected,
                    sprintf('%s -> %s', $from->value, $to->value),
                );
            }
        }
    });

    it('marks exactly the approved terminal states', function (): void {
        foreach (PaymentStatus::cases() as $status) {
            expect($status->isTerminal())->toBe(
                $status === PaymentStatus::Reversed,
                $status->value,
            );
        }
    });

    it('exposes a translated label for every stored value', function (): void {
        foreach (PaymentStatus::cases() as $status) {
            expect($status->label())
                ->toBeString()
                ->not->toBe('')
                ->not->toBe('admin.');
        }
    });
});
