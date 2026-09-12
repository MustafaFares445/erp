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
use App\Models\DocumentExport;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\SalesPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SalesPermissionSeeder)->run();
    Bus::fake();
});

it('hides the invoice export action for an actor without sales.export', function (): void {
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo(SalesPermission::InvoiceView->value);

    Livewire::actingAs($viewerOnly)
        ->test(ListInvoices::class)
        ->assertActionHidden('export_csv');
});

it('exports only the invoices matching the active status filter, not the whole table', function (): void {
    $exporter = User::factory()->create();
    $exporter->givePermissionTo(SalesPermission::InvoiceView->value);
    $exporter->givePermissionTo(SalesPermission::Export->value);

    $draft = Invoice::factory()->create(['invoice_number' => 'INV-DRAFT-001', 'status' => InvoiceStatus::Draft]);
    $sent = Invoice::factory()->create(['invoice_number' => 'INV-SENT-001', 'status' => InvoiceStatus::Sent]);

    Livewire::actingAs($exporter)
        ->test(ListInvoices::class)
        ->filterTable('status', InvoiceStatus::Sent->value)
        ->assertActionVisible('export_csv')
        ->callAction('export_csv');

    $export = DocumentExport::query()->where('module', 'sales')->where('type', 'invoices')->sole();

    expect($export->parameters['record_ids'])->toBe([$sent->getKey()])
        ->and($export->parameters['record_ids'])->not->toContain($draft->getKey());
});

it('records who exported, when, and which filters were active', function (): void {
    $exporter = User::factory()->create();
    $exporter->givePermissionTo(SalesPermission::InvoiceView->value);
    $exporter->givePermissionTo(SalesPermission::Export->value);

    Invoice::factory()->create(['status' => InvoiceStatus::Sent]);

    Livewire::actingAs($exporter)
        ->test(ListInvoices::class)
        ->filterTable('status', InvoiceStatus::Sent->value)
        ->callAction('export_csv');

    $log = AuditLog::query()->where('description', 'sales.invoice.exported')->where('causer_id', $exporter->getKey())->first();

    expect($log)->not->toBeNull()
        ->and($log->getProperty('filters'))->toHaveKey('status')
        ->and($log->created_at)->not->toBeNull();
});

it('gates the payment, quotation, credit note, and order exports behind sales.export too', function (string $viewPermission, string $pageClass): void {
    $viewerOnly = User::factory()->create();
    $viewerOnly->givePermissionTo($viewPermission);

    Livewire::actingAs($viewerOnly)
        ->test($pageClass)
        ->assertActionHidden('export_csv');
})->with([
    'payments' => [SalesPermission::PaymentView->value, ListPayments::class],
    'quotations' => [SalesPermission::QuotationView->value, ListQuotations::class],
    'credit notes' => [SalesPermission::CreditNoteView->value, ListCreditNotes::class],
    'orders' => [SalesPermission::OrderView->value, ListOrders::class],
]);
