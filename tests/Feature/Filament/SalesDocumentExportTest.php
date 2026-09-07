<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\SalesPermission;
use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
});

/**
 * Streams a page's CSV export action and captures the emitted body, since
 * `response()->streamDownload()` has no return value to inspect until sent.
 */
function captureSalesExportCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return ob_get_clean();
}

it('refuses the invoice export for an actor without sales.export, even called directly', function (): void {
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(SalesPermission::InvoiceView->value);

    $page = new ListInvoices;
    auth()->login($viewerOnly);

    $reflection = new ReflectionMethod($page, 'exportSalesDocumentsCsv');

    expect(fn (): mixed => $reflection->invoke($page))->toThrow(HttpException::class);
});

it('exports only the invoices matching the active status filter, not the whole table', function (): void {
    $exporter = User::factory()->create();
    $exporter->givePermissionTo(SalesPermission::InvoiceView->value);
    $exporter->givePermissionTo(SalesPermission::Export->value);

    $draft = Invoice::factory()->create(['invoice_number' => 'INV-DRAFT-001', 'status' => InvoiceStatus::Draft]);
    $sent = Invoice::factory()->create(['invoice_number' => 'INV-SENT-001', 'status' => InvoiceStatus::Sent]);

    $component = Livewire::actingAs($exporter)
        ->test(ListInvoices::class)
        ->filterTable('status', InvoiceStatus::Sent->value)
        ->assertOk();

    $reflection = new ReflectionMethod($component->instance(), 'exportSalesDocumentsCsv');
    /** @var StreamedResponse $response */
    $response = $reflection->invoke($component->instance());
    $csv = captureSalesExportCsv($response);

    expect($csv)->toContain($sent->invoice_number)
        ->and($csv)->not->toContain($draft->invoice_number);
});

it('records who exported, when, and which filters were active', function (): void {
    $exporter = User::factory()->create();
    $exporter->givePermissionTo(SalesPermission::InvoiceView->value);
    $exporter->givePermissionTo(SalesPermission::Export->value);

    Invoice::factory()->create(['status' => InvoiceStatus::Sent]);

    $component = Livewire::actingAs($exporter)
        ->test(ListInvoices::class)
        ->filterTable('status', InvoiceStatus::Sent->value)
        ->assertOk();

    $reflection = new ReflectionMethod($component->instance(), 'exportSalesDocumentsCsv');
    $reflection->invoke($component->instance());

    $log = AuditLog::query()->where('description', 'sales.invoice.exported')->where('causer_id', $exporter->getKey())->first();

    expect($log)->not->toBeNull()
        ->and($log->getProperty('filters'))->toHaveKey('status')
        ->and($log->created_at)->not->toBeNull();
});

it('gates the payment, quotation, credit note, and order exports behind sales.export too', function (string $viewPermission, string $pageClass): void {
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo($viewPermission);

    $page = new $pageClass;
    auth()->login($viewerOnly);

    $reflection = new ReflectionMethod($page, 'exportSalesDocumentsCsv');

    expect(fn (): mixed => $reflection->invoke($page))->toThrow(HttpException::class);
})->with([
    'payments' => [SalesPermission::PaymentView->value, ListPayments::class],
    'quotations' => [SalesPermission::QuotationView->value, ListQuotations::class],
    'credit notes' => [SalesPermission::CreditNoteView->value, ListCreditNotes::class],
    'orders' => [SalesPermission::OrderView->value, ListOrders::class],
]);
