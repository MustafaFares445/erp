<?php

declare(strict_types=1);

use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\Sales\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('ignores legacy non-numeric payment suffixes when generating the next number', function (): void {
    $customer = CustomerProfile::factory()->create();

    Payment::factory()->create([
        'payment_number' => 'PAY-000001',
        'customer_id' => $customer,
        'payment_method_id' => PaymentMethod::factory(),
        'payment_date' => today(),
        'amount' => '10.00',
    ]);

    Payment::factory()->create([
        'payment_number' => 'PAY-SALES-2026-001',
        'customer_id' => $customer,
        'payment_method_id' => PaymentMethod::factory(),
        'payment_date' => today(),
        'amount' => '20.00',
    ]);

    expect(app(DocumentNumberGenerator::class)->next(
        Payment::withTrashed(),
        'payment_number',
        'PAY-',
    ))->toBe('PAY-000002');
});
