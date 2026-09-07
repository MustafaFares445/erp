<?php

declare(strict_types=1);

use App\Data\Support\LabourEntryData;
use App\Enums\AccountingPermission;
use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\SalesPermission;
use App\Models\ChartAccount;
use App\Models\EmployeeProfile;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceCostService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
});

/**
 * Billing a service job through {@see MaintenanceBillingService} delegates to
 * the Sales module's own {@see InvoiceService}, which
 * authorizes independently of Support — the actor needs both the Support
 * billing permission and ordinary Sales invoicing rights, exactly as a real
 * biller would.
 */
function billingTestManager(): User
{
    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    foreach ([SalesPermission::InvoiceManage, SalesPermission::InvoiceIssue, SalesPermission::PaymentRecord] as $permission) {
        $manager->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }
    $manager->givePermissionTo(Permission::findOrCreate(AccountingPermission::JournalEntryPostFromSource->value, 'web'));

    return $manager;
}

function billingTestSalesSettings(float $taxPercent = 10.0): void
{
    $account = fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();

    SalesSetting::query()->create([
        'default_tax_percent' => (string) $taxPercent,
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $account('1200'),
        'revenue_account_id' => $account('4100'),
        'deferred_tax_account_id' => $account('2350'),
        'tax_payable_account_id' => $account('2300'),
        'customer_deposits_account_id' => $account('2400'),
        'bad_debt_expense_account_id' => $account('6800'),
    ]);
}

/**
 * Builds a closed maintenance record with one consumed part (priced via a
 * real ProductVariant base price) and one labour entry.
 *
 * @return array{0: MaintenanceRecord, 1: MaintenanceTask, 2: User}
 */
function billableMaintenanceRecord(User $manager): array
{
    $variant = ProductVariant::factory()->create(['base_price' => '100.00', 'min_price' => null]);
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 10,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 10,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($stock->warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $task = MaintenanceTask::factory()->create(['status' => MaintenanceStatus::InProgress]);
    $record = $task->maintenanceRecord;

    app(ServiceRecordPartService::class)->consume($task, $variant->getKey(), $stock->warehouse_id, 2.0, $manager, $lot->getKey());

    $employee = User::factory()->admin()->create();
    EmployeeProfile::factory()->withHourlyRate(6000)->create(['user_id' => $employee->getKey()]);
    app(MaintenanceCostService::class)->recordLabour(new LabourEntryData(
        maintenanceRecordId: $record->getKey(),
        serviceRecordId: $task->getKey(),
        employeeId: $employee->getKey(),
        performedOn: now()->toDateString(),
        minutes: 60,
    ), $manager);

    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::InProgress, $manager);
    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Closed, $manager);
    app(MaintenanceRecordService::class)->transition($record, MaintenanceStatus::Closed, $manager);

    return [$record->refresh(), $task, $manager];
}

it('creates an invoice linked both ways, with parts at sale price carrying provenance and labour as a service line', function (): void {
    billingTestSalesSettings();
    $manager = billingTestManager();
    [$record] = billableMaintenanceRecord($manager);

    $invoice = app(MaintenanceBillingService::class)->createInvoice($record, $manager);

    expect($invoice->maintenance_record_id)->toBe($record->getKey())
        ->and($record->refresh()->invoice_id)->toBe($invoice->getKey())
        ->and($record->billing_type)->toBe(MaintenanceBillingType::Invoiced)
        ->and($record->billed_at)->not->toBeNull();

    $invoice->load('lines');
    $partLine = $invoice->lines->firstWhere('product_variant_id', '!=', null);
    $labourLine = $invoice->lines->firstWhere('description', 'Labour');

    expect($partLine)->not->toBeNull()
        ->and($partLine->resolved_price_source)->not->toBeNull()
        ->and((float) $partLine->quantity)->toBe(2.0)
        ->and($labourLine)->not->toBeNull()
        ->and((float) $labourLine->unit_price)->toBe(60.0);
});

it('refuses to bill an open maintenance request', function (): void {
    $manager = billingTestManager();
    $record = MaintenanceRecord::factory()->create(['status' => MaintenanceStatus::Open]);

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice($record, $manager))
        ->toThrow(InvalidBillingTransition::class);
});

it('refuses to bill the same maintenance request twice', function (): void {
    billingTestSalesSettings();
    $manager = billingTestManager();
    [$record] = billableMaintenanceRecord($manager);

    app(MaintenanceBillingService::class)->createInvoice($record, $manager);

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice($record->refresh(), $manager))
        ->toThrow(InvalidBillingTransition::class);
});

it('refuses to invoice a warranty-covered request until it is reclassified, and audits the reclassification with its reason', function (): void {
    billingTestSalesSettings();
    $manager = billingTestManager();
    [$record] = billableMaintenanceRecord($manager);

    $billing = app(MaintenanceBillingService::class);
    $billing->markWarrantyCovered($record, $manager, 'Manufacturer warranty applies');

    expect(fn () => $billing->createInvoice($record->refresh(), $manager))
        ->toThrow(InvalidBillingTransition::class);

    $billing->reclassifyWarrantyForBilling($record->refresh(), $manager, 'Customer declined warranty claim, billing instead');

    $activity = Activity::query()
        ->where('description', 'support.maintenance_record.warranty_reclassified')
        ->where('subject_id', $record->getKey())
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->getProperty('reason'))->toBe('Customer declined warranty claim, billing instead');

    $invoice = $billing->createInvoice($record->refresh(), $manager);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($record->refresh()->billing_type)->toBe(MaintenanceBillingType::Invoiced);
});

it('follows the standard invoice path: issuing posts the normal entry, and collecting recognises tax proportionally against the same accounts as a goods invoice', function (): void {
    FiscalPeriod::factory()->create();
    billingTestSalesSettings(10.0);
    $manager = billingTestManager();
    [$record] = billableMaintenanceRecord($manager);

    $invoice = app(MaintenanceBillingService::class)->createInvoice($record, $manager);
    $invoice = app(InvoiceService::class)->issue($manager, $invoice);

    $account = fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();

    $entry = JournalEntry::query()->whereMorphedTo('source', $invoice)->sole();
    $lines = $entry->lines()->get();

    expect($lines->firstWhere('chart_account_id', $account('1200')))->not->toBeNull()
        ->and($lines->firstWhere('chart_account_id', $account('4100')))->not->toBeNull()
        ->and($lines->firstWhere('chart_account_id', $account('2350')))->not->toBeNull();

    $paymentMethod = PaymentMethod::factory()->create([
        'chart_account_id' => $account('1110'),
        'requires_proof' => false,
        'is_active' => true,
    ]);

    $payment = app(PaymentService::class)->createDraft($manager, [
        'customer_id' => $invoice->customer_id,
        'payment_method_id' => $paymentMethod->getKey(),
        'amount' => (string) $invoice->total_amount,
        'currency' => 'USD',
        'payment_date' => today()->toDateString(),
    ]);

    app(PaymentService::class)->post($manager, $payment, [[
        'invoice_id' => (int) $invoice->getKey(),
        'amount' => (string) $invoice->total_amount,
    ]]);

    expect($invoice->refresh()->outstandingMinor())->toBe(0)
        ->and((float) $invoice->recognised_tax_amount)->toBeGreaterThan(0.0);
});
