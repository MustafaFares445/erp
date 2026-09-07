<?php

declare(strict_types=1);

use App\Enums\SupplierPaymentStatus;

$legalTransitions = [
    'draft' => ['paid', 'cancelled'],
    'paid' => [],
    'cancelled' => [],
];

describe('SupplierPaymentStatus', function () use ($legalTransitions): void {
    it('permits exactly the approved lifecycle transitions', function () use ($legalTransitions): void {
        foreach (SupplierPaymentStatus::cases() as $from) {
            foreach (SupplierPaymentStatus::cases() as $to) {
                $expected = in_array($to->value, $legalTransitions[$from->value], true);

                expect($from->canTransitionTo($to))->toBe(
                    $expected,
                    sprintf('%s -> %s', $from->value, $to->value),
                );
            }
        }
    });

    it('marks exactly the approved terminal states', function (): void {
        $terminalValues = ['paid', 'cancelled'];

        foreach (SupplierPaymentStatus::cases() as $status) {
            expect($status->isTerminal())
                ->toBe(in_array($status->value, $terminalValues, true), $status->value);
        }
    });

    it('exposes a translated label for every stored value', function (): void {
        foreach (SupplierPaymentStatus::cases() as $status) {
            expect($status->label())
                ->toBeString()
                ->not->toBe('')
                ->not->toBe('admin.');
        }
    });
});
