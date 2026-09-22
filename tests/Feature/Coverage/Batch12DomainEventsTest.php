<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Events\CustomerDepositApplied;
use App\Events\CustomerReturnRequestSubmitted;
use App\Events\CustomerReturnRequestUpdated;
use App\Events\PaymentTransactionFailed;
use App\Events\PaymentTransactionSucceeded;
use App\Events\ShipmentCustomerConfirmed;
use App\Models\ChartAccount;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CustomerReturnRequestService;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Payments\CustomerDepositApplicationService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Providers\FakeStripeClient;
use App\Services\Payments\Providers\StripeClientInterface;
use App\Services\Payments\Providers\StripePaymentIntentData;
use App\Services\Payments\StripePaymentReconciliationService;
use App\Services\Shipments\ShipmentArrivalConfirmationService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function batch12SalesAccounting(): void
{
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
}

it('dispatches ShipmentCustomerConfirmed only for a customer-initiated confirmation', function (): void {
    Storage::fake('local');
    Event::fake([ShipmentCustomerConfirmed::class]);

    $customer = CustomerProfile::factory()->create();
    $shipment = Shipment::factory()->forCustomer($customer)->create();

    app(ShipmentArrivalConfirmationService::class)->confirmByCustomer(
        $shipment,
        $customer,
        [UploadedFile::fake()->image('proof.jpg')],
    );

    Event::assertDispatched(ShipmentCustomerConfirmed::class);

    $admin = User::factory()->admin()->create();
    $adminShipment = Shipment::factory()->create();
    Event::fake([ShipmentCustomerConfirmed::class]);

    app(ShipmentArrivalConfirmationService::class)->confirmByAdmin($adminShipment, $admin);

    Event::assertNotDispatched(ShipmentCustomerConfirmed::class);
});

it('dispatches PaymentTransactionSucceeded and PaymentTransactionFailed only on a genuine transition', function (): void {
    Event::fake([PaymentTransactionSucceeded::class, PaymentTransactionFailed::class]);

    $fake = new FakeStripeClient;
    app()->instance(StripeClientInterface::class, $fake);

    $transaction = PaymentTransaction::factory()->create([
        'status' => PaymentTransactionStatus::Pending,
        'payment_intent_id' => 'pi_event_test',
    ]);
    $fake->paymentIntents['pi_event_test'] = new StripePaymentIntentData(
        id: 'pi_event_test',
        status: 'succeeded',
        amountMinor: $transaction->amount_minor,
        currency: $transaction->currency,
        latestChargeId: null,
    );

    $service = app(StripePaymentReconciliationService::class);
    $service->reconcile($transaction);
    $service->reconcile($transaction->refresh());

    Event::assertDispatchedTimes(PaymentTransactionSucceeded::class, 1);
    Event::assertNotDispatched(PaymentTransactionFailed::class);
});

it('dispatches CustomerDepositApplied when a deposit is applied to an invoice', function (): void {
    batch12SalesAccounting();
    Event::fake([CustomerDepositApplied::class]);

    $customer = CustomerProfile::factory()->create();
    $method = PaymentMethod::factory()->create([
        'type' => PaymentMethodType::BankTransfer,
        'chart_account_id' => (int) ChartAccount::query()->where('code', '1100')->sole()->getKey(),
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

    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '60.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);

    app(CustomerDepositApplicationService::class)->applyEligibleDeposits($invoice);

    Event::assertDispatched(CustomerDepositApplied::class, fn (CustomerDepositApplied $event): bool => $event->invoice->is($invoice));
});

it('dispatches CustomerReturnRequestSubmitted on submit and CustomerReturnRequestUpdated on every later transition', function (): void {
    Event::fake([CustomerReturnRequestSubmitted::class, CustomerReturnRequestUpdated::class]);

    $customer = CustomerProfile::factory()->create();
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $line = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);
    app(InventoryOperationService::class)->markReady($delivery, $actor);
    app(InventoryOperationService::class)->complete($delivery->refresh(), $actor);

    $service = app(CustomerReturnRequestService::class);
    $admin = User::factory()->admin()->create();

    $request = $service->submit(
        customer: $customer,
        delivery: $delivery->refresh(),
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    );
    $service->reject($admin, $request, 'not eligible');

    Event::assertDispatchedTimes(CustomerReturnRequestSubmitted::class, 1);
    Event::assertDispatchedTimes(CustomerReturnRequestUpdated::class, 1);
});
