<?php

declare(strict_types=1);

use App\Filament\Resources\Campaigns\Actions\CampaignActions;
use App\Filament\Resources\CreditNotes\Actions\CreditNoteActions;
use App\Filament\Resources\CustomerQuotationRequests\Actions\CustomerQuotationRequestActions;
use App\Filament\Resources\Customers\Actions\CustomerApprovalActions;
use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Resources\JournalEntries\Actions\JournalEntryActions;
use App\Filament\Resources\SupplierConfirmations\Actions\SupplierConfirmationActions;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\CustomerQuotationRequest;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers guest early returns across customer sales accounting and purchasing actions', function (): void {
    auth()->logout();

    $customer = CustomerProfile::factory()->create();
    $request = CustomerQuotationRequest::factory()->create();
    $invoice = Invoice::factory()->create();
    $entry = JournalEntry::factory()->create();
    $confirmation = SupplierConfirmation::factory()->create();

    CustomerApprovalActions::approve()->getActionFunction()($customer, []);
    CustomerApprovalActions::requestChanges()->getActionFunction()($customer, []);
    CustomerApprovalActions::reject()->getActionFunction()($customer, []);
    CustomerApprovalActions::reactivate()->getActionFunction()($customer, []);
    CustomerQuotationRequestActions::startReview()->getActionFunction()($request);
    CustomerQuotationRequestActions::convert()->getActionFunction()($request);
    CustomerQuotationRequestActions::reject()->getActionFunction()($request, ['reason' => 'guest']);

    InvoiceActions::retryDepositApplication()->getActionFunction()($invoice);

    JournalEntryActions::post()->getActionFunction()($entry);
    JournalEntryActions::reverse()->getActionFunction()($entry, []);

    SupplierConfirmationActions::response()->getActionFunction()($confirmation, []);

    expect(true)->toBeTrue();
});

it('requires an authenticated CRM actor for campaign actions', function (): void {
    auth()->logout();

    $actor = new ReflectionMethod(CampaignActions::class, 'actor');

    expect(fn (): mixed => $actor->invoke(null))
        ->toThrow(LogicException::class, 'authenticated CRM user');
});
it('covers the regenerate PDF label for a credit note with generated media', function (): void {
    $creditNote = CreditNote::factory()->create();

    $pdf = storage_path('framework/testing/credit-note-action-coverage.pdf');
    @mkdir(dirname($pdf), 0777, true);
    file_put_contents($pdf, '%PDF-1.4 coverage');
    $creditNote->addMedia($pdf)->toMediaCollection('credit-note-pdf', 'local');

    $action = CreditNoteActions::generatePdf()->record($creditNote->refresh());

    expect($action->getLabel())->toBe(__('admin.sales.actions.regenerate_pdf'));
});
it('covers supplier confirmation fallback labels for missing variant and reference context', function (): void {
    $confirmation = SupplierConfirmation::factory()->create();
    $variant = ProductVariant::factory()->create();
    $item = SupplierConfirmationItem::factory()->create([
        'supplier_confirmation_id' => $confirmation->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_order_line_id' => null,
        'requested_base_quantity' => '2.000000',
    ]);
    $variant->delete();

    $defaults = new ReflectionMethod(SupplierConfirmationActions::class, 'commitmentDefaults');
    $rows = $defaults->invoke(null, $confirmation);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['product'])->toBe((string) $item->product_variant_id)
        ->and($rows[0]['supplier_reference'])->toBe('—');
});
