<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;

$legalTransitions = {
    'draft': [
        'issued',
        'cancelled'
    ],
    'issued': [
        'sent',
        'cancelled'
    ],
    'sent': [
        'written_off',
        'cancelled'
    ],
    'written_off': [],
    'cancelled': []
};

describe('InvoiceStatus', function () use ($legalTransitions): void {
    it('permits exactly the approved lifecycle transitions', function () use ($legalTransitions): void {
        foreach (InvoiceStatus::cases() as $from) {
            foreach (InvoiceStatus::cases() as $to) {
                $expected = in_array($to->value, $legalTransitions[$from->value], true);

                expect($from->canTransitionTo($to))->toBe(
                    $expected,
                    sprintf('%s -> %s', $from->value, $to->value),
                );
            }
        }
    });

    it('marks exactly the approved terminal states', function (): void {
        $terminalValues = ['written_off','cancelled'];

        foreach (InvoiceStatus::cases() as $status) {
            expect($status->isTerminal())
                ->toBe(in_array($status->value, $terminalValues, true), $status->value);
        }
    });

    it('exposes a translated label for every stored value', function (): void {
        foreach (InvoiceStatus::cases() as $status) {
            expect($status->label())
                ->toBeString()
                ->not->toBe('')
                ->not->toBe('admin.');
        }
    });
});
