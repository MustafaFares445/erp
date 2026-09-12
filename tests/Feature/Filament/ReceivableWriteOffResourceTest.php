<?php

declare(strict_types=1);

use App\Enums\InvoiceStatus;
use App\Enums\WriteOffReason;
use App\Enums\WriteOffStatus;
use App\Filament\Resources\ReceivableWriteOffs\Pages\CreateReceivableWriteOff;
use App\Filament\Resources\ReceivableWriteOffs\Pages\ListReceivableWriteOffs;
use App\Filament\Resources\ReceivableWriteOffs\Pages\ViewReceivableWriteOff;
use App\Filament\Resources\ReceivableWriteOffs\ReceivableWriteOffResource;
use App\Models\CustomerProfile;
use App\Models\FiscalPeriod;
use App\Models\Invoice;
use App\Models\ReceivableWriteOff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPostingAccounts();

    FiscalPeriod::factory()->create();

    $this->recorder = actingAsAccountant();
    $this->approver = actingAsChiefAccountant();

    $this->customer = CustomerProfile::factory()->create();
});

function filamentWriteOffInvoice(CustomerProfile $customer): Invoice
{
    return Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'status' => InvoiceStatus::Sent,
        'issued_at' => now()->subDay(),
        'sent_at' => now()->subHour(),
        'subtotal' => '100.00',
        'tax_total' => '10.00',
        'total_amount' => '110.00',
        'amount_paid' => '0.00',
        'credited_amount' => '0.00',
        'recognised_tax_amount' => '0.00',
    ]);
}

it('lists and creates a receivable write-off through the accounting resource', function (): void {
    $invoice = filamentWriteOffInvoice($this->customer);

    Livewire::actingAs($this->recorder)
        ->test(ListReceivableWriteOffs::class)
        ->assertSuccessful();

    Livewire::actingAs($this->recorder)
        ->test(CreateReceivableWriteOff::class)
        ->fillForm([
            'customer_id' => $this->customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount' => '110.00',
            'reason_category' => WriteOffReason::CommerciallyUneconomic->value,
            'reason' => 'Recovery cost exceeds the expected collection.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $writeOff = ReceivableWriteOff::query()->sole();

    expect($writeOff->status)->toBe(WriteOffStatus::Draft)
        ->and($writeOff->amount_minor)->toBe(11_000)
        ->and($writeOff->recorded_by)->toBe($this->recorder->getKey())
        ->and($writeOff->write_off_number)->toMatch('/^WO-'.now()->format('Y').'-\\d{5}$/');

    Livewire::actingAs($this->recorder)
        ->test(ListReceivableWriteOffs::class)
        ->assertCanSeeTableRecords([$writeOff]);
});

it('renders write-off evidence and approves it through the view action', function (): void {
    $invoice = filamentWriteOffInvoice($this->customer);

    $writeOff = ReceivableWriteOff::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_minor' => 11_000,
        'status' => WriteOffStatus::Draft,
        'reason_category' => WriteOffReason::Insolvency,
        'recorded_by' => $this->recorder->getKey(),
        'fiscal_period_id' => FiscalPeriod::query()->sole()->getKey(),
    ]);

    Livewire::actingAs($this->approver)
        ->test(ViewReceivableWriteOff::class, ['record' => $writeOff->getKey()])
        ->assertSuccessful()
        ->assertSee($writeOff->write_off_number)
        ->assertActionVisible('approve_write_off')
        ->assertActionVisible('cancel_write_off')
        ->callAction('approve_write_off')
        ->assertHasNoActionErrors();

    expect($writeOff->refresh()->status)->toBe(WriteOffStatus::Approved)
        ->and($writeOff->journal_entry_id)->not->toBeNull()
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::WrittenOff);

    Livewire::actingAs($this->approver)
        ->test(ViewReceivableWriteOff::class, ['record' => $writeOff->getKey()])
        ->assertActionHidden('approve_write_off')
        ->assertActionHidden('cancel_write_off');
});

it('cancels a draft through the view action without changing the invoice', function (): void {
    $invoice = filamentWriteOffInvoice($this->customer);

    $writeOff = ReceivableWriteOff::factory()->create([
        'customer_id' => $this->customer->getKey(),
        'invoice_id' => $invoice->getKey(),
        'amount_minor' => 5_000,
        'status' => WriteOffStatus::Draft,
        'recorded_by' => $this->recorder->getKey(),
        'fiscal_period_id' => FiscalPeriod::query()->sole()->getKey(),
    ]);

    Livewire::actingAs($this->recorder)
        ->test(ViewReceivableWriteOff::class, ['record' => $writeOff->getKey()])
        ->callAction('cancel_write_off', data: [
            'reason' => 'Customer resumed the collection discussion.',
        ])
        ->assertHasNoActionErrors();

    expect($writeOff->refresh()->status)->toBe(WriteOffStatus::Cancelled)
        ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
});

it('registers the resource against the canonical model', function (): void {
    expect(ReceivableWriteOffResource::getModel())->toBe(ReceivableWriteOff::class)
        ->and(ReceivableWriteOffResource::getNavigationLabel())->toBe(__('admin.resources.receivable_write_offs'));
});
