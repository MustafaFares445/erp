<?php

declare(strict_types=1);

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Filament\Resources\PaymentTransactions\Pages\ListPaymentTransactions;
use App\Filament\Resources\PaymentTransactions\Pages\ViewPaymentTransaction;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\SalesSetting;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Payments\ProviderPaymentSettlementService;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\Providers\StripePaymentIntentData;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SlaPolicySeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new ChartOfAccountsSeeder)->run();
    FiscalPeriod::factory()->create();

    $account = static fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();

    SalesSetting::query()->create([
        'default_tax_percent' => '0.00',
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $account('1200'),
        'revenue_account_id' => $account('4100'),
        'deferred_tax_account_id' => $account('2350'),
        'tax_payable_account_id' => $account('2300'),
        'customer_deposits_account_id' => $account('2400'),
        'bad_debt_expense_account_id' => $account('6800'),
    ]);

    $this->admin = User::factory()->admin()->create();
    $this->stripeMethod = PaymentMethod::factory()->create([
        'type' => PaymentMethodType::Stripe,
        'chart_account_id' => $account('1100'),
        'is_active' => true,
    ]);
});

it('lists payment transactions with their provider and settlement status', function (): void {
    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create();
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
    ]);

    Livewire::actingAs($this->admin)
        ->test(ListPaymentTransactions::class)
        ->assertCanSeeTableRecords([$transaction]);
});

it('retries settlement for a succeeded, unsettled transaction from the view page', function (): void {
    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create(['grand_total' => '50.00']);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'amount_minor' => 5000,
        'payment_intent_id' => 'pi_view_retry',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ViewPaymentTransaction::class, ['record' => $transaction->getKey()])
        ->callAction('retry_settlement')
        ->assertNotified();

    expect($transaction->refresh()->payment_id)->not->toBeNull();
});

it('refreshes provider status from Stripe from the view page', function (): void {
    $fake = new FakeStripeClient;
    $this->app->instance(StripeClientInterface::class, $fake);

    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create();
    $transaction = PaymentTransaction::factory()->create([
        'customer_id' => $customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'status' => PaymentTransactionStatus::Pending,
        'payment_intent_id' => 'pi_view_refresh',
    ]);
    $fake->paymentIntents['pi_view_refresh'] = new StripePaymentIntentData(
        id: 'pi_view_refresh',
        status: 'requires_action',
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        latestChargeId: null,
    );

    Livewire::actingAs($this->admin)
        ->test(ViewPaymentTransaction::class, ['record' => $transaction->getKey()])
        ->callAction('refresh_status')
        ->assertNotified();

    expect($transaction->refresh()->status)->toBe(PaymentTransactionStatus::RequiresAction);
});

it('retries settlement for a chargeable ticket payment transaction from the view page', function (): void {
    (new SupportPermissionSeeder)->run();
    (new SlaPolicySeeder)->run();

    $ticket = Ticket::factory()->chargeable()->create();
    $link = TicketPaymentLink::factory()->for($ticket)->create(['amount' => '40.00', 'currency' => 'USD']);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $ticket->customer_id,
        'purpose_type' => TicketPaymentLink::class,
        'purpose_id' => $link->getKey(),
        'payment_intent_id' => 'pi_ticket_view_retry',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ViewPaymentTransaction::class, ['record' => $transaction->getKey()])
        ->callAction('retry_settlement')
        ->assertNotified();

    expect($link->refresh()->status)->toBe(PaymentLinkStatus::Settled);
});

it('hides the settle and refresh actions once a transaction is already settled', function (): void {
    $customer = CustomerProfile::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create(['grand_total' => '50.00']);
    $transaction = PaymentTransaction::factory()->succeeded()->create([
        'customer_id' => $customer->getKey(),
        'purpose_type' => Order::class,
        'purpose_id' => $order->getKey(),
        'amount_minor' => 5000,
        'payment_intent_id' => 'pi_view_hidden',
    ]);

    app(ProviderPaymentSettlementService::class)->settle($transaction);

    Livewire::actingAs($this->admin)
        ->test(ViewPaymentTransaction::class, ['record' => $transaction->refresh()->getKey()])
        ->assertActionHidden('retry_settlement')
        ->assertActionHidden('refresh_status');
});
