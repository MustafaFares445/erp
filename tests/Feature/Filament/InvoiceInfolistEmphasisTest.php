<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('emphasizes total, paid, credited, outstanding, and allocations on the invoice view', function (): void {
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create();

    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);

    $payment = Payment::query()->forceCreate([
        'payment_number' => 'PAY-EMPH-'.fake()->unique()->numerify('######'),
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '40.00',
        'currency' => 'AED',
        'payment_date' => today()->toDateString(),
        'status' => 'draft',
    ]);

    app(PaymentAllocationService::class)->allocate($payment, $invoice->getKey(), 40.0);

    Livewire::actingAs($admin)
        ->test(ViewInvoice::class, ['record' => $invoice->refresh()->getKey()])
        ->assertSee('Outstanding')
        ->assertSee('60.00')
        ->assertSee('Electronic document')
        ->assertSee('Not generated yet')
        ->assertSee($payment->payment_number);
});
