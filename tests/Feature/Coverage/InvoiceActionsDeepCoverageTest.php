<?php

declare(strict_types=1);

use App\Enums\InvoiceConfirmationType;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Actions\InvoiceActions;
use App\Jobs\GenerateInvoiceDocument;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Sales\InvoiceConfirmationService;
use App\Services\Sales\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('executes invoice action callbacks and queue paths', function (): void {
    Queue::fake();
    Storage::fake('local');

    $actor = User::factory()->create();
    $this->actingAs($actor);

    $invoiceService = new class
    {
        public int $issued = 0;

        public int $sent = 0;

        public function issue(User $actor, Invoice $invoice): Invoice
        {
            $this->issued++;

            return $invoice;
        }

        public function send(User $actor, Invoice $invoice): Invoice
        {
            $this->sent++;

            return $invoice;
        }
    };
    app()->instance(InvoiceService::class, $invoiceService);

    $confirmationService = new class
    {
        /** @var list<array{type:string,notes:?string,signature:?string}> */
        public array $calls = [];

        public function confirm(
            User $actor,
            Invoice $invoice,
            string $type,
            ?string $notes,
            ?string $signature,
        ): Invoice {
            $this->calls[] = ['type' => $type, 'notes' => $notes, 'signature' => $signature];

            return $invoice;
        }
    };
    app()->instance(InvoiceConfirmationService::class, $confirmationService);

    $draft = Invoice::factory()->create([
        'status' => InvoiceStatus::Draft,
        'total_amount' => '100.00',
    ]);
    InvoiceActions::issue()->getActionFunction()($draft);

    expect($invoiceService->issued)->toBe(1);

    $issued = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '100.00',
        'credited_amount' => '0.00',
        'amount_paid' => '0.00',
    ]);

    $generate = InvoiceActions::generatePdf()->record($issued);
    expect($generate->getLabel())->toBe('Generate PDF')
        ->and($generate->isVisible())->toBeTrue();

    $generate->getActionFunction()($issued);
    Queue::assertPushed(GenerateInvoiceDocument::class);

    $pdf = storage_path('framework/testing/disks/local/invoice-actions-coverage.pdf');
    @mkdir(dirname($pdf), 0777, true);
    file_put_contents($pdf, '%PDF-1.4 coverage');
    $issued->addMedia($pdf)->toMediaCollection('invoice-pdf', 'local');

    $generateWithMedia = InvoiceActions::generatePdf()->record($issued->refresh());
    expect($generateWithMedia->getLabel())->toBe('Regenerate PDF');

    $send = InvoiceActions::send()->record($issued->refresh());
    expect($send->isVisible())->toBeTrue();
    $send->getActionFunction()($issued->refresh());

    expect($invoiceService->sent)->toBe(1);

    $sent = Invoice::factory()->create([
        'status' => InvoiceStatus::Sent,
        'issued_at' => now(),
        'sent_at' => now(),
        'total_amount' => '100.00',
    ]);
    Storage::disk('local')->put('invoice-confirmation-signatures/coverage.png', 'signature');

    $confirm = InvoiceActions::confirmReceipt()->record($sent);
    expect($confirm->isVisible())->toBeTrue();

    $confirm->getActionFunction()($sent, [
        'confirmation_type' => InvoiceConfirmationType::CustomerReceived->value,
        'notes' => 'Confirmed by coverage test',
        'signature' => 'invoice-confirmation-signatures/coverage.png',
    ]);

    expect($confirmationService->calls)->toHaveCount(1)
        ->and($confirmationService->calls[0]['notes'])->toBe('Confirmed by coverage test')
        ->and($confirmationService->calls[0]['signature'])->not->toBeNull();
});

it('covers invoice action early exits visibility and destination urls', function (): void {
    Gate::before(static fn (): bool => true);

    $issued = Invoice::factory()->create([
        'status' => InvoiceStatus::Issued,
        'issued_at' => now(),
        'total_amount' => '100.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
    ]);

    $actor = User::factory()->create();
    $this->actingAs($actor);

    expect(InvoiceActions::recordPayment()->record($issued)->isVisible())->toBeTrue()
        ->and(InvoiceActions::recordPayment()->record($issued)->getUrl())->toContain('invoice_id='.$issued->getKey())
        ->and(InvoiceActions::writeOff()->record($issued)->isVisible())->toBeTrue()
        ->and(InvoiceActions::writeOff()->record($issued)->getUrl())->toContain('invoice_id='.$issued->getKey())
        ->and(InvoiceActions::createCreditNote()->record($issued)->isVisible())->toBeTrue()
        ->and(InvoiceActions::createCreditNote()->record($issued)->getUrl())->toContain('invoice_id='.$issued->getKey());

    auth()->logout();

    InvoiceActions::issue()->getActionFunction()($issued);
    InvoiceActions::generatePdf()->getActionFunction()($issued);
    InvoiceActions::send()->getActionFunction()($issued);
    InvoiceActions::confirmReceipt()->getActionFunction()($issued, [
        'confirmation_type' => InvoiceConfirmationType::CustomerReceived->value,
    ]);

    $can = new ReflectionMethod(InvoiceActions::class, 'can');
    expect($can->invoke(null, 'send', $issued))->toBeFalse();
});
