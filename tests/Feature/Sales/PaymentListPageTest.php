<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\Widgets\PaymentsOverview;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

function paymentSalesUser(): User
{
    $user = User::factory()->admin()->create();
    $user->assignRole(DashboardRole::BillingOfficer->value);

    return $user;
}

function makePayment(array $overrides = []): Payment
{
    return Payment::factory()->create(array_merge([
        'payment_number' => 'PAY-'.fake()->unique()->numberBetween(1, 999_999),
        'customer_id' => CustomerProfile::factory(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '100.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => PaymentStatus::Draft,
    ], $overrides));
}

it('scopes collected-this-month payments to posted, non-reversed ones', function (): void {
    makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now()]);
    makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now()->subMonths(2)]);
    makePayment(['status' => PaymentStatus::Draft]);

    expect(Payment::query()->collectedThisMonth()->count())->toBe(1);
});

it('groups collected-this-month totals by currency', function (): void {
    makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'currency' => 'USD', 'amount' => '100.00']);
    makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'currency' => 'AED', 'amount' => '50.00']);

    $totals = Payment::query()->collectedThisMonth()
        ->selectRaw('currency, SUM(amount) as total')
        ->groupBy('currency')
        ->pluck('total', 'currency');

    expect($totals->get('USD'))->toEqual(100.0)
        ->and($totals->get('AED'))->toEqual(50.0);
});

it('scopes customer deposits to posted payments with an unapplied balance', function (): void {
    $fullyAllocated = makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);
    $fullyAllocated->allocations()->create(['invoice_id' => Invoice::factory()->create()->getKey(), 'amount' => '100.00']);

    $partial = makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);
    $partial->allocations()->create(['invoice_id' => Invoice::factory()->create()->getKey(), 'amount' => '40.00']);

    makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);

    expect(Payment::query()->customerDeposits()->count())->toBe(2);
});

it('calculates the current customer deposit from current allocations', function (): void {
    $payment = makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);
    $invoice = Invoice::factory()->create();
    $payment->allocations()->create(['invoice_id' => $invoice->getKey(), 'amount' => '35.00']);

    expect($payment->fresh()->allocatedAmountMinor())->toBe(3500)
        ->and($payment->fresh()->customerDepositMinor())->toBe(6500);

    $payment->allocations()->create([
        'invoice_id' => Invoice::factory()->create()->getKey(),
        'amount' => '65.00',
    ]);

    expect($payment->fresh()->customerDepositMinor())->toBe(0);
});

it('renders the payments overview stats widget and list page', function (): void {
    makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now()]);

    Livewire::actingAs(paymentSalesUser())
        ->test(PaymentsOverview::class)
        ->assertSuccessful();

    Livewire::actingAs(paymentSalesUser())
        ->test(ListPayments::class)
        ->assertSuccessful()
        ->assertSee('Customer deposits');
});

it('shows only posted payments with a current unapplied balance in the customer deposits tab', function (): void {
    $deposit = makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);
    $deposit->allocations()->create(['invoice_id' => Invoice::factory()->create()->getKey(), 'amount' => '25.00']);
    $fullyApplied = makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);
    $fullyApplied->allocations()->create(['invoice_id' => Invoice::factory()->create()->getKey(), 'amount' => '100.00']);
    $draft = makePayment(['status' => PaymentStatus::Draft, 'amount' => '100.00']);

    Livewire::actingAs(paymentSalesUser())
        ->test(ListPayments::class)
        ->set('activeTab', 'customer_deposits')
        ->assertCanSeeTableRecords([$deposit])
        ->assertCanNotSeeTableRecords([$fullyApplied, $draft]);
});

it('shows the current deposit and invoice application on a posted payment', function (): void {
    $payment = makePayment(['status' => PaymentStatus::Posted, 'posted_at' => now(), 'amount' => '100.00']);
    $payment->allocations()->create(['invoice_id' => Invoice::factory()->create()->getKey(), 'amount' => '40.00']);

    Livewire::actingAs(paymentSalesUser())
        ->test(ViewPayment::class, ['record' => $payment->getKey()])
        ->assertSuccessful()
        ->assertSee('Available customer deposit')
        ->assertSee('60.00')
        ->assertSee('Current invoice outstanding');
});

it('filters payments by customer', function (): void {
    $matching = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $wanted = makePayment(['customer_id' => $matching->getKey()]);
    $unwanted = makePayment(['customer_id' => $other->getKey()]);

    Livewire::actingAs(paymentSalesUser())
        ->test(ListPayments::class)
        ->filterTable('customer_id', $matching->getKey())
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});
