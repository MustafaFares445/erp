<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Enums\SalesPermission;
use App\Filament\Pages\SalesDashboard;
use App\Filament\Resources\Bills\Pages\EditBill;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Bills\Pages\ViewBill;
use App\Filament\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ManageExpenses;
use App\Filament\Resources\InventoryCorrections\Pages\ViewInventoryCorrection;
use App\Filament\Resources\InventoryCorrections\RelationManagers\CorrectionLinesRelationManager;
use App\Filament\Resources\InventoryLots\Pages\ViewInventoryLot;
use App\Filament\Resources\InventoryLots\RelationManagers\LotBalancesRelationManager;
use App\Filament\Resources\InventoryReservations\Pages\ListInventoryReservations;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Resources\PaymentMethods\Pages\ViewPaymentMethod;
use App\Filament\Resources\Payments\Pages\EditPayment;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\SupplierPayments\Pages\EditSupplierPayment;
use App\Filament\Resources\SupplierPayments\Pages\ManageSupplierPayments;
use App\Filament\Resources\SupplierProductSupports\Pages\ManageSupplierProductSupports;
use App\Filament\Widgets\SalesRevenueTrend;
use App\Filament\Widgets\SalesStatistics;
use App\Models\Bill;
use App\Models\CreditNote;
use App\Models\Expense;
use App\Models\InventoryCorrection;
use App\Models\InventoryLot;
use App\Models\InventoryReservation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Quotation;
use App\Models\SupplierPayment;
use App\Models\SupplierProductSupport;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    (new AccountingPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    (new PurchasePermissionSeeder)->run();

    $this->coverageAdmin = User::factory()->admin()->create();
    $this->coverageAdmin->givePermissionTo([
        ...AccountingPermission::values(),
        ...SalesPermission::values(),
        ...InventoryPermission::values(),
        ...PurchasePermission::values(),
    ]);
});

function coverageDraftPayment(): Payment
{
    $invoice = Invoice::factory()->create(['status' => 'draft', 'issued_at' => null]);
    $method = PaymentMethod::factory()->create();

    return Payment::query()->create([
        'payment_number' => 'PAY-COVERAGE-FILAMENT',
        'customer_id' => $invoice->customer_id,
        'payment_method_id' => $method->getKey(),
        'amount' => '25.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => today(),
        'status' => 'draft',
    ]);
}

function coverageDraftCreditNote(): CreditNote
{
    $invoice = Invoice::factory()->create(['status' => 'draft', 'issued_at' => null]);

    return CreditNote::query()->create([
        'credit_note_number' => 'CN-COVERAGE-FILAMENT',
        'invoice_id' => $invoice->getKey(),
        'customer_id' => $invoice->customer_id,
        'reason' => 'Coverage smoke',
        'issue_date' => today(),
        'subtotal' => '10.00',
        'tax_total' => '0.50',
        'grand_total' => '10.50',
        'status' => 'draft',
    ]);
}

it('mounts the previously uncovered sales record surfaces', function (): void {
    $invoice = Invoice::factory()->create(['status' => 'draft', 'issued_at' => null]);
    $credit = coverageDraftCreditNote();
    $payment = coverageDraftPayment();
    $method = $payment->paymentMethod()->firstOrFail();
    $order = Order::factory()->create();
    $quotation = Quotation::factory()->create();

    foreach ([
        [ListInvoices::class, []],
        [ViewInvoice::class, ['record' => $invoice->getRouteKey()]],
        [EditInvoice::class, ['record' => $invoice->getRouteKey()]],
        [ListCreditNotes::class, []],
        [ViewCreditNote::class, ['record' => $credit->getRouteKey()]],
        [EditCreditNote::class, ['record' => $credit->getRouteKey()]],
        [ListPayments::class, []],
        [ViewPayment::class, ['record' => $payment->getRouteKey()]],
        [EditPayment::class, ['record' => $payment->getRouteKey()]],
        [ListPaymentMethods::class, []],
        [ViewPaymentMethod::class, ['record' => $method->getRouteKey()]],
        [EditPaymentMethod::class, ['record' => $method->getRouteKey()]],
        [EditOrder::class, ['record' => $order->getRouteKey()]],
        [EditQuotation::class, ['record' => $quotation->getRouteKey()]],
    ] as [$page, $parameters]) {
        Livewire::actingAs($this->coverageAdmin)
            ->test($page, $parameters)
            ->assertSuccessful();
    }
});

it('mounts the previously uncovered accounting and purchasing record surfaces', function (): void {
    $bill = Bill::factory()->create(['status' => 'draft']);
    $expense = Expense::factory()->create(['status' => 'draft']);
    $supplierPayment = SupplierPayment::factory()->create(['status' => 'draft']);
    SupplierProductSupport::factory()->create();

    foreach ([
        [ManageBills::class, []],
        [ViewBill::class, ['record' => $bill->getRouteKey()]],
        [EditBill::class, ['record' => $bill->getRouteKey()]],
        [ManageExpenses::class, []],
        [EditExpense::class, ['record' => $expense->getRouteKey()]],
        [ManageSupplierPayments::class, []],
        [EditSupplierPayment::class, ['record' => $supplierPayment->getRouteKey()]],
        [ManageSupplierProductSupports::class, []],
    ] as [$page, $parameters]) {
        Livewire::actingAs($this->coverageAdmin)
            ->test($page, $parameters)
            ->assertSuccessful();
    }
});

it('mounts read-only inventory reservation, correction, and lot balance surfaces', function (): void {
    InventoryReservation::factory()->create();
    $correction = InventoryCorrection::factory()->create();
    $lot = InventoryLot::factory()->canonical()->create();

    Livewire::actingAs($this->coverageAdmin)
        ->test(ListInventoryReservations::class)
        ->assertSuccessful();

    Livewire::actingAs($this->coverageAdmin)
        ->test(ViewInventoryCorrection::class, ['record' => $correction->getRouteKey()])
        ->assertSuccessful();

    Livewire::actingAs($this->coverageAdmin)
        ->test(CorrectionLinesRelationManager::class, [
            'ownerRecord' => $correction,
            'pageClass' => ViewInventoryCorrection::class,
        ])
        ->assertSuccessful();

    Livewire::actingAs($this->coverageAdmin)
        ->test(LotBalancesRelationManager::class, [
            'ownerRecord' => $lot,
            'pageClass' => ViewInventoryLot::class,
        ])
        ->assertSuccessful();
});

it('covers sales dashboard access branches, labels, widgets, and chart rendering', function (): void {
    Auth::logout();

    expect(SalesDashboard::canAccess())->toBeFalse()
        ->and(SalesStatistics::canView())->toBeFalse()
        ->and(SalesRevenueTrend::canView())->toBeFalse();

    $quotationViewer = User::factory()->employee()->create();
    $quotationViewer->givePermissionTo(SalesPermission::QuotationView->value);
    $this->actingAs($quotationViewer);

    expect(SalesDashboard::canAccess())->toBeTrue()
        ->and(SalesStatistics::canView())->toBeTrue()
        ->and(SalesRevenueTrend::canView())->toBeTrue();

    $orderViewer = User::factory()->employee()->create();
    $orderViewer->givePermissionTo(SalesPermission::OrderView->value);
    $this->actingAs($orderViewer);

    expect(SalesDashboard::canAccess())->toBeTrue()
        ->and(SalesStatistics::canView())->toBeTrue()
        ->and(SalesRevenueTrend::canView())->toBeTrue();

    $invoiceViewer = User::factory()->employee()->create();
    $invoiceViewer->givePermissionTo(SalesPermission::InvoiceView->value);
    $this->actingAs($invoiceViewer);

    expect(SalesDashboard::canAccess())->toBeTrue()
        ->and(SalesStatistics::canView())->toBeTrue()
        ->and(SalesRevenueTrend::canView())->toBeTrue()
        ->and(SalesDashboard::getNavigationLabel())->not->toBe('')
        ->and((new SalesDashboard)->getTitle())->not->toBe('');

    Invoice::factory()->create([
        'invoice_date' => today(),
        'due_date' => today()->subDay(),
        'total_amount' => '100.00',
        'amount_paid' => '25.00',
        'status' => 'issued',
    ]);
    Quotation::factory()->create();
    Order::factory()->create(['payment_status' => 'unpaid']);

    Livewire::actingAs($invoiceViewer)
        ->test(SalesStatistics::class)
        ->assertSuccessful();

    Livewire::actingAs($invoiceViewer)
        ->test(SalesRevenueTrend::class)
        ->assertSuccessful();

    $stats = new SalesStatistics;
    $formatMoney = new ReflectionMethod($stats, 'formatMoney');
    expect($formatMoney->invoke($stats, 1234.5))->toBe('1,234.50');

    $trend = new SalesRevenueTrend;
    $type = new ReflectionMethod($trend, 'getType');
    expect($type->invoke($trend))->toBe('line');

    $widgets = new ReflectionMethod(SalesDashboard::class, 'getHeaderWidgets');
    expect($widgets->invoke(new SalesDashboard))->toBe([
        SalesStatistics::class,
        SalesRevenueTrend::class,
    ]);
});
