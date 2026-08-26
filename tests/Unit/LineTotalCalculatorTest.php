<?php

declare(strict_types=1);

use App\Services\Sales\LineTotalCalculator;

beforeEach(function (): void {
    $this->calculator = new LineTotalCalculator;
});

it('computes the default tax for a line at the configured rate', function (): void {
    // qty 2 x 100.00 at 5% => tax 10.00, line total 210.00 (US2 scenario 6).
    $tax = $this->calculator->defaultTax(2, 100.00, 5.0);

    expect($tax)->toBe(10.00)
        ->and($this->calculator->lineTotal(2, 100.00, $tax))->toBe(210.00);
});

it('produces zero tax at a zero rate', function (): void {
    $tax = $this->calculator->defaultTax(3, 50.00, 0.0);

    expect($tax)->toBe(0.00)
        ->and($this->calculator->lineTotal(3, 50.00, $tax))->toBe(150.00);
});

it('accepts a manually overridden tax amount independent of the default', function (): void {
    // A per-line override (FR-017) does not have to match defaultTax() at all —
    // lineTotal() only ever adds whatever tax amount it is given.
    expect($this->calculator->lineTotal(1, 200.00, 0.00))->toBe(200.00)
        ->and($this->calculator->lineTotal(1, 200.00, 25.00))->toBe(225.00);
});

it('rounds the subtotal before adding tax, not after', function (): void {
    // 3 x 33.335 = 100.005, which rounds to 100.01 before tax is added.
    $tax = $this->calculator->defaultTax(3, 33.335, 10.0);
    $total = $this->calculator->lineTotal(3, 33.335, $tax);

    expect($total)->toBe(round(100.01 + $tax, 2));
});

it('sums lines into document totals', function (): void {
    $lines = [
        ['subtotal' => 100.00, 'tax_amount' => 5.00, 'line_total' => 105.00],
        ['subtotal' => 200.00, 'tax_amount' => 10.00, 'line_total' => 210.00],
    ];

    expect($this->calculator->documentTotals($lines))->toBe([
        'subtotal' => 300.00,
        'tax_total' => 15.00,
        'grand_total' => 315.00,
    ]);
});

it('returns zero totals for an empty line set', function (): void {
    expect($this->calculator->documentTotals([]))->toBe([
        'subtotal' => 0.00,
        'tax_total' => 0.00,
        'grand_total' => 0.00,
    ]);
});
