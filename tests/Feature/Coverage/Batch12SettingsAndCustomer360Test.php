<?php

declare(strict_types=1);

use App\Enums\PaymentMethodType;
use App\Enums\SerializedCustodyType;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\CustomerOwnedEquipmentRelationManager;
use App\Filament\Resources\Customers\RelationManagers\CustomerPaymentTransactionsRelationManager;
use App\Filament\Resources\SalesSettings\Pages\ManageSalesSettings;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\SerializedInventoryUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('saves the Stripe and auto-deposit-application settings from the dashboard', function (): void {
    $admin = User::factory()->admin()->create();
    $method = PaymentMethod::factory()->create(['type' => PaymentMethodType::Stripe]);

    Livewire::actingAs($admin)
        ->test(ManageSalesSettings::class)
        ->callAction('create', [
            'default_tax_percent' => 5,
            'default_quotation_validity_days' => 30,
            'stripe_enabled' => true,
            'stripe_payment_method_id' => $method->getKey(),
            'auto_apply_customer_deposits' => false,
        ])
        ->assertHasNoActionErrors();

    $settings = SalesSetting::current()->refresh();

    expect($settings->stripe_enabled)->toBeTrue()
        ->and($settings->stripe_payment_method_id)->toBe($method->getKey())
        ->and($settings->auto_apply_customer_deposits)->toBeFalse();
});

it('skips automatic deposit application when the setting is disabled', function (): void {
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
        'auto_apply_customer_deposits' => false,
    ]);

    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create([
        'type' => PaymentMethodType::BankTransfer,
        'chart_account_id' => $account('1100'),
        'is_active' => true,
    ]);
    $actor = User::factory()->admin()->create();

    $draft = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '100.00',
        'currency' => 'AED',
        'payment_date' => now()->toDateString(),
    ]);
    $payment = app(PaymentService::class)->post($actor, $draft, []);

    $invoice = app(InvoiceService::class)->createStandalone(
        $actor,
        ['customer_id' => $customer->getKey()],
        [['quantity' => 1, 'unit_price' => 60, 'tax_amount' => 0]],
    );

    app(InvoiceService::class)->issue($actor, $invoice);

    expect($payment->refresh()->allocations()->count())->toBe(0);
});

it('computes a customer deposit balance from posted, unallocated payments', function (): void {
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

    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create([
        'type' => PaymentMethodType::BankTransfer,
        'chart_account_id' => $account('1100'),
        'is_active' => true,
    ]);
    $actor = User::factory()->admin()->create();

    $draft = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '100.00',
        'currency' => 'AED',
        'payment_date' => now()->toDateString(),
    ]);
    app(PaymentService::class)->post($actor, $draft, []);

    expect($customer->depositBalance())->toBe(100.0);
});

it('lists a customer own payment transactions and owned equipment on the Customer 360 view', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();

    $transaction = PaymentTransaction::factory()->create(['customer_id' => $customer->getKey()]);

    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
    ]);

    Livewire::actingAs($admin)
        ->test(CustomerPaymentTransactionsRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->assertCanSeeTableRecords([$transaction]);

    Livewire::actingAs($admin)
        ->test(CustomerOwnedEquipmentRelationManager::class, [
            'ownerRecord' => $customer,
            'pageClass' => ViewCustomer::class,
        ])
        ->assertCanSeeTableRecords([$unit]);
});
