<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\MaintenanceStatus;
use App\Enums\SalesPermission;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryStock;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\SalesSetting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Sales\InvoiceService;
use App\Services\Support\MaintenanceBillingService;
use App\Services\Support\MaintenanceRecordService;
use App\Services\Support\ServiceRecordPartService;
use App\Services\Support\ServiceRecordService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * F-06 (Docs/CROSS_MODULE_BUSINESS_FLOWS.md) — completed chargeable service
 * work must invoice, collect, and recognise tax exactly as any goods invoice
 * does (GAP-MW-10, "a second revenue path would be a second tax policy").
 */
it('carries a ticket through maintenance, invoicing, payment, and proportional tax recognition', function (): void {
    (new SupportPermissionSeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    FiscalPeriod::factory()->create();

    $account = fn (string $code): int => (int) ChartAccount::query()->where('code', $code)->sole()->getKey();
    SalesSetting::query()->create([
        'default_tax_percent' => '5.00',
        'default_quotation_validity_days' => 30,
        'receivable_account_id' => $account('1200'),
        'revenue_account_id' => $account('4100'),
        'deferred_tax_account_id' => $account('2350'),
        'tax_payable_account_id' => $account('2300'),
        'customer_deposits_account_id' => $account('2400'),
        'bad_debt_expense_account_id' => $account('6800'),
    ]);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');
    foreach ([SalesPermission::InvoiceManage, SalesPermission::InvoiceIssue, SalesPermission::PaymentRecord] as $permission) {
        $manager->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
    }
    $manager->givePermissionTo(Permission::findOrCreate(AccountingPermission::JournalEntryPostFromSource->value, 'web'));

    $ticket = Ticket::factory()->create();

    $record = app(MaintenanceRecordService::class)->createFromTicket($ticket, [
        'description' => $ticket->description,
    ], $manager);

    $task = app(ServiceRecordService::class)->create($record, [
        'title' => 'Replace faulty part',
    ], $manager);

    $variant = ProductVariant::factory()->create(['base_price' => '200.00']);
    $stock = InventoryStock::factory()->for($variant)->create([
        'on_hand_quantity' => 5,
        'reserved_quantity' => 0,
        'damaged_quantity' => 0,
        'available_quantity' => 5,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($stock->warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    app(ServiceRecordPartService::class)->consume($task, $variant->getKey(), $stock->warehouse_id, 1.0, $manager, $lot->getKey());

    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::InProgress, $manager);
    app(ServiceRecordService::class)->transition($task, MaintenanceStatus::Closed, $manager);
    app(MaintenanceRecordService::class)->transition($record->refresh(), MaintenanceStatus::Closed, $manager);

    $invoice = app(MaintenanceBillingService::class)->createInvoice($record->refresh(), $manager);
    $invoice = app(InvoiceService::class)->issue($manager, $invoice);

    expect($invoice->maintenance_record_id)->toBe($record->getKey())
        ->and((float) $invoice->tax_total)->toBeGreaterThan(0.0);

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
        ->and((float) $invoice->recognised_tax_amount)->toBeGreaterThan(0.0)
        ->and($record->refresh()->billing_type->value)->toBe('invoiced');
});
