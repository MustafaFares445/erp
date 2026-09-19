<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\PurchaseOrderPrintController;
use App\Jobs\DispatchCampaignJob;
use App\Jobs\GenerateCreditNoteDocument;
use App\Jobs\GenerateInvoiceDocument;
use App\Jobs\SendInvoiceEmail;
use App\Models\Campaign;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Crm\CampaignDispatchService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sales\InvoiceBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
it('executes campaign dispatch job through its service guard', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-COVERAGE-001',
        'name' => 'Coverage campaign',
        'channel' => CampaignChannel::Email,
        'scheduled_at' => now(),
        'segment_criteria' => [],
        'created_by' => $actor->getKey(),
    ])->save();

    $job = new DispatchCampaignJob($campaign->getKey(), $actor->getKey());

    expect(fn () => $job->handle(app(CampaignDispatchService::class)))
        ->toThrow(DomainException::class, 'A campaign requires recipients before sending.');
});

it('executes invoice document generation guard path', function (): void {
    $actor = User::factory()->create();
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    $job = new GenerateInvoiceDocument($invoice->getKey(), $actor->getKey());

    expect(fn () => $job->handle())
        ->toThrow(DomainException::class, 'Only an issued invoice can generate its final PDF.');
});
it('executes credit note document generation guard path', function (): void {
    $actor = User::factory()->create();
    $creditNote = CreditNote::factory()->create(['status' => CreditNoteStatus::Draft]);
    $job = new GenerateCreditNoteDocument($creditNote->getKey(), $actor->getKey());

    expect(fn () => $job->handle())
        ->toThrow(DomainException::class, 'Only a confirmed credit note can generate its PDF.');
});

it('executes invoice email lifecycle guards', function (): void {
    $actor = User::factory()->create();
    $draft = Invoice::factory()->create(['status' => InvoiceStatus::Draft]);
    $job = new SendInvoiceEmail($draft->getKey(), $actor->getKey());

    expect(fn () => $job->handle(app(InvoiceBalanceService::class), app(NotificationDispatcher::class)))
        ->toThrow(DomainException::class, 'Only an issued or sent invoice can be emailed.');

    $issued = Invoice::factory()->create(['status' => InvoiceStatus::Issued]);
    $job = new SendInvoiceEmail($issued->getKey(), $actor->getKey());

    expect(fn () => $job->handle(app(InvoiceBalanceService::class), app(NotificationDispatcher::class)))
        ->toThrow(DomainException::class, 'Generate the invoice PDF before sending it.');
});
it('renders the purchase order print view for an authorized actor', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->create();
    $purchaseOrder = PurchaseOrder::withoutEvents(
        static fn (): PurchaseOrder => PurchaseOrder::factory()->create(),
    );
    $request = Request::create('/coverage/purchase-order-print', 'GET');
    $request->setUserResolver(static fn (): User => $actor);

    $view = (new PurchaseOrderPrintController)($request, $purchaseOrder);

    expect($view->name())->toBe('purchasing.purchase-orders.print')
        ->and($view->getData()['purchaseOrder'])->toBeInstanceOf(PurchaseOrder::class);
});

it('generates and stores the final invoice PDF', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
    ]);

    new GenerateInvoiceDocument($invoice->getKey(), $actor->getKey())->handle();

    $invoice->refresh();
    $media = $invoice->getFirstMedia('invoice-pdf');
    expect($media)->not->toBeNull()
        ->and($media?->file_name)->toStartWith($invoice->invoice_number.'-')
        ->and($media?->file_name)->toEndWith('.pdf');
});

it('generates and stores the confirmed credit note PDF', function (): void {
    Storage::fake('local');
    $actor = User::factory()->create();
    $creditNote = CreditNote::factory()->create([
        'status' => CreditNoteStatus::Confirmed,
        'confirmed_at' => now(),
    ]);
    new GenerateCreditNoteDocument($creditNote->getKey(), $actor->getKey())->handle();

    $creditNote->refresh();
    $media = $creditNote->getFirstMedia('credit-note-pdf');
    expect($media)->not->toBeNull()
        ->and($media?->file_name)->toStartWith($creditNote->credit_note_number.'-')
        ->and($media?->file_name)->toEndWith('.pdf');
});
