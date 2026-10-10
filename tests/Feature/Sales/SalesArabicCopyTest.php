<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Widgets\InvoicesOverview;
use App\Filament\Resources\Orders\Widgets\OrdersOverview;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Models\InventoryOperation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Support\MoneyFormatter;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SalesPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// 2026-10-10 release QA (Wave 1): these Sales list and invoice texts stayed English on Arabic screens.

beforeEach(function (): void {
    (new SystemPermissionSeeder)->run();
    (new SalesPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();

    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    $this->actingAs($admin);

    app()->setLocale('ar');
});

it('shows the invoice next action and balance breakdown in Arabic', function (): void {
    $invoice = Invoice::factory()->create(['subtotal' => 1000, 'tax_total' => 50, 'total_amount' => 1050, 'amount_paid' => 50, 'issued_at' => now()]);

    Livewire::test(ListInvoices::class)
        ->assertTableColumnStateSet('next_action', 'تحصيل '.MoneyFormatter::format($invoice->outstandingMinor()), $invoice)
        ->assertTableColumnHasDescription('outstanding', 'مدفوع: 50.00', $invoice);
});

it('explains the invoice balance and next step in Arabic on the invoice page', function (): void {
    $invoice = Invoice::factory()->create(['subtotal' => 1000, 'tax_total' => 50, 'total_amount' => 1050, 'amount_paid' => 50, 'issued_at' => now()]);
    $outstanding = MoneyFormatter::format($invoice->outstandingMinor());

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('يتبقى '.$outstanding.' مستحقاً. التالي: تحصيل '.$outstanding.'.')
        ->assertDontSee('remains outstanding');
});

it('leaves the next-step sentence out once the invoice needs no action', function (): void {
    $invoice = Invoice::factory()->create(['subtotal' => 1000, 'tax_total' => 50, 'total_amount' => 1050, 'amount_paid' => 1050, 'issued_at' => now()]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('لا يوجد رصيد مستحق متبقٍ.')
        ->assertDontSee('التالي:');
});

it('shows the quotation stock coverage in Arabic', function (): void {
    $quotation = Quotation::factory()->accepted()->create();

    Livewire::test(ListQuotations::class)
        ->assertTableColumnFormattedStateSet('reservation_coverage', 'متوفر', $quotation);
});

it('describes the value of active orders in Arabic', function (): void {
    Order::factory()->create(['status' => OrderStatus::Confirmed->value]);

    Livewire::test(OrdersOverview::class)
        ->assertSee('قيد التنفيذ')
        ->assertDontSee('in progress');
});

// Wave 3 screenshot review: the overdue amount on the Arabic invoice list stayed English.
it('describes the overdue invoice amount in Arabic', function (): void {
    $invoice = Invoice::factory()->create(['subtotal' => 1000, 'tax_total' => 50, 'total_amount' => 1050, 'amount_paid' => 50, 'issued_at' => now()->subMonth(), 'due_date' => now()->subWeek()]);

    Livewire::test(InvoicesOverview::class)
        ->assertSeeText(MoneyFormatter::format($invoice->outstandingMinor()).' متأخرة الاستحقاق')
        ->assertDontSeeText('overdue');
});

it('shows whether a delivery note was invoiced in Arabic', function (): void {
    $delivery = InventoryOperation::factory()->delivery()->done()->create();

    Livewire::test(ListDeliveryNotes::class)
        ->assertTableColumnStateSet('invoiced', 'غير مفوتر', $delivery);
});
