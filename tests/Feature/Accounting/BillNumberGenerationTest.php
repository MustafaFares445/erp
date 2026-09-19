<?php

declare(strict_types=1);

use App\Models\Bill;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ignores numbers in another format when issuing the next one', function (): void {
    // Regression: nextBillNumber() used to read a string MAX(bill_number).
    // 'BILL-DEMO-2026-001' sorts above 'BILL-0000002' because 'D' > '0', so the
    // trailing digits read back as 001 and the generator reissued BILL-0000002
    // — violating the unique index and crashing purchase-order acceptance,
    // which creates a draft bill as part of its fan-out.
    Bill::factory()->create(['bill_number' => 'BILL-DEMO-2026-001']);
    Bill::factory()->create(['bill_number' => 'BILL-0000002']);

    expect(Bill::nextBillNumber())->toBe('BILL-0000003');
});

it('starts the sequence when no conforming number exists', function (): void {
    Bill::factory()->create(['bill_number' => 'BILL-DEMO-2026-001']);

    expect(Bill::nextBillNumber())->toBe('BILL-0000001');
});

it('issues a unique number for each bill created alongside a foreign format', function (): void {
    Bill::factory()->create(['bill_number' => 'BILL-DEMO-2026-001']);

    $first = Bill::factory()->create();
    $second = Bill::factory()->create();

    expect($first->bill_number)->not->toBe($second->bill_number)
        ->and(Bill::query()->count())->toBe(3);
});

it('does not reissue a number belonging to a soft-deleted bill', function (): void {
    $bill = Bill::factory()->create();
    $issued = $bill->bill_number;
    $bill->delete();

    expect(Bill::nextBillNumber())->not->toBe($issued);
});
