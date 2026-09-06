<?php

declare(strict_types=1);

use App\Enums\CreditNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OpportunityCloseReason;
use App\Enums\OpportunityStage;
use App\Enums\PaymentStatus;
use App\Enums\SalesPermission;
use App\Enums\SalesReportType;
use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\InvoiceDeliveryLink;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PriceFloorOverride;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\TaxRegisterService;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(SalesReportService::class);
});

it('lists a completed delivery with no invoice link and excludes one covered by a standalone invoice referencing it', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');

    $uninvoiced = InventoryOperation::factory()->delivery()->done()->create([
        'completed_at' => $asOf->subDays(10),
    ]);

    $invoiced = InventoryOperation::factory()->delivery()->done()->create([
        'completed_at' => $asOf->subDays(5),
    ]);
    $coveringInvoice = Invoice::factory()->create(['issued_at' => $asOf->subDays(4)]);
    InvoiceDeliveryLink::factory()->create([
        'invoice_id' => $coveringInvoice->getKey(),
        'inventory_operation_id' => $invoiced->getKey(),
    ]);

    // A standalone invoice that references no delivery must not hide the uninvoiced delivery.
    Invoice::factory()->create(['issued_at' => $asOf->subDays(3)]);

    $report = $this->service->deliveredNotInvoiced($asOf);
    $operationIds = collect($report['deliveries'])->pluck('inventory_operation_id')->all();

    expect($operationIds)->toContain($uninvoiced->getKey())
        ->and($operationIds)->not->toContain($invoiced->getKey())
        ->and($report['count'])->toBe(1);
});

it('excludes a delivery not yet completed and one completed after the as-of date', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');

    InventoryOperation::factory()->delivery()->create(); // still draft, never completed
    InventoryOperation::factory()->delivery()->done()->create(['completed_at' => $asOf->addDay()]);

    $report = $this->service->deliveredNotInvoiced($asOf);

    expect($report['count'])->toBe(0);
});

it('matches AccountsReceivableService::aging figure-for-figure for the same as-of date', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $customer = CustomerProfile::factory()->create(['company_name' => 'Acme Dental']);

    Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'invoice_date' => $asOf->subDays(40)->toDateString(),
        'due_date' => $asOf->subDays(10)->toDateString(),
        'issued_at' => $asOf->subDays(40),
        'subtotal' => '200.00',
        'tax_total' => '0.00',
        'total_amount' => '200.00',
        'status' => InvoiceStatus::Sent,
    ]);

    $ar = app(AccountsReceivableService::class)->aging($asOf);
    $report = $this->service->invoicedNotCollected($asOf);

    expect($report)->toEqual($ar);
});

it('delegates tax recognition summary to TaxRegisterService without recomputing', function (): void {
    $from = CarbonImmutable::parse('2026-01-01');
    $to = CarbonImmutable::parse('2026-12-31');

    $expected = app(TaxRegisterService::class)->period($from, $to);
    $report = $this->service->taxRecognitionSummary($from, $to);

    expect($report)->toEqual($expected);
});

it('ranks loss reasons and computes win rate per owner using the first-class stage and close-reason enums', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05');
    $owner = User::factory()->create();

    SalesOpportunity::factory()->manual()->create([
        'owner_id' => $owner->getKey(),
        'stage' => OpportunityStage::ClosedWon,
        'close_reason' => OpportunityCloseReason::WonAsQuoted,
        'closed_at' => $asOf->subDays(2),
    ]);
    SalesOpportunity::factory()->manual()->create([
        'owner_id' => $owner->getKey(),
        'stage' => OpportunityStage::ClosedLost,
        'close_reason' => OpportunityCloseReason::LostOnPrice,
        'closed_at' => $asOf->subDays(1),
    ]);
    SalesOpportunity::factory()->manual()->create([
        'owner_id' => $owner->getKey(),
        'stage' => OpportunityStage::ClosedLost,
        'close_reason' => OpportunityCloseReason::LostOnPrice,
        'closed_at' => $asOf,
    ]);
    // Still open — must not be counted as won or lost.
    SalesOpportunity::factory()->manual()->create(['stage' => OpportunityStage::Proposal]);

    $report = $this->service->winLossAnalysis(null, null);

    expect($report['total_closed'])->toBe(3)
        ->and($report['won_count'])->toBe(1)
        ->and($report['lost_count'])->toBe(2)
        ->and($report['loss_reasons'][0])->toBe(['reason' => 'lost_on_price', 'count' => 2]);
});

it('surfaces persisted floor-override provenance without recomputing it', function (): void {
    $approver = User::factory()->create(['name' => 'Pricing Manager']);

    PriceFloorOverride::factory()->create([
        'approved_by' => $approver->getKey(),
        'approved_at' => CarbonImmutable::parse('2026-09-01 10:00:00'),
        'attempted_price' => '40.00',
        'min_price' => '50.00',
        'reason' => 'Loyalty concession',
    ]);

    $report = $this->service->discountAndFloorOverrides();

    expect($report['count'])->toBe(1)
        ->and($report['overrides'][0]['approved_by_name'])->toBe('Pricing Manager')
        ->and($report['overrides'][0]['attempted_price'])->toBe('40.00')
        ->and($report['overrides'][0]['min_price'])->toBe('50.00')
        ->and($report['overrides'][0]['reason'])->toBe('Loyalty concession');
});

it('lists a posted return with no confirmed credit note and drops it once one is confirmed', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $customer = CustomerProfile::factory()->create();

    $return = InventoryReturn::factory()->customer()->posted()->create([
        'customer_id' => $customer->getKey(),
        'posted_at' => $asOf->subDays(5),
    ]);

    $report = $this->service->returnsWithoutCredit($asOf);
    expect($report['count'])->toBe(1);

    CreditNote::factory()->create([
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $return->customer_id,
        'status' => CreditNoteStatus::Confirmed,
        'confirmed_at' => $asOf->subDay(),
    ]);

    $report = $this->service->returnsWithoutCredit($asOf);
    expect($report['count'])->toBe(0);
});

it('does not treat a draft credit note as coverage for a posted return', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $customer = CustomerProfile::factory()->create();

    $return = InventoryReturn::factory()->customer()->posted()->create([
        'customer_id' => $customer->getKey(),
        'posted_at' => $asOf->subDays(5),
    ]);

    CreditNote::factory()->create([
        'inventory_return_id' => $return->getKey(),
        'customer_id' => $return->customer_id,
        'status' => CreditNoteStatus::Draft,
    ]);

    $report = $this->service->returnsWithoutCredit($asOf);

    expect($report['count'])->toBe(1);
});

it('reports invoiced and collected revenue by customer', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $customer = CustomerProfile::factory()->create(['company_name' => 'Beta Clinic']);

    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->getKey(),
        'issued_at' => $asOf->subDays(10),
        'subtotal' => '300.00',
        'tax_total' => '0.00',
        'total_amount' => '300.00',
        'status' => InvoiceStatus::Sent,
    ]);

    $payment = Payment::factory()->create([
        'payment_number' => 'PAY-REV-001',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => PaymentMethod::factory(),
        'amount' => '100.00',
        'currency' => 'USD',
        'source' => 'manual',
        'payment_date' => $asOf->subDays(2)->toDateString(),
        'status' => PaymentStatus::Posted,
        'posted_at' => $asOf->subDays(2),
    ]);
    $payment->allocations()->create(['invoice_id' => $invoice->getKey(), 'amount' => '100.00']);

    $report = $this->service->customerRevenue($asOf);
    $row = collect($report['customers'])->firstWhere('customer_id', $customer->getKey());

    expect($row['invoiced_minor'])->toBe(30_000)
        ->and($row['collected_minor'])->toBe(10_000);
});

it('gates every report type behind the report-view permission', function (): void {
    $withPermission = User::factory()->create();
    $withPermission->givePermissionTo(Permission::findOrCreate(SalesPermission::ReportView->value, 'web'));

    $withoutPermission = User::factory()->create();

    foreach (SalesReportType::cases() as $type) {
        expect($this->service->canView($withPermission, $type))->toBeTrue()
            ->and($this->service->canView($withoutPermission, $type))->toBeFalse();
    }
});

it('never posts a journal entry or mutates any domain record as a side effect of running a report', function (): void {
    $asOf = CarbonImmutable::parse('2026-09-05 12:00:00');
    $customer = CustomerProfile::factory()->create();
    Invoice::factory()->create(['customer_id' => $customer->getKey(), 'issued_at' => $asOf->subDays(5)]);
    InventoryOperation::factory()->delivery()->done()->create(['completed_at' => $asOf->subDays(3)]);
    InventoryReturn::factory()->customer()->posted()->create(['posted_at' => $asOf->subDays(2)]);

    $journalCountBefore = JournalEntry::query()->count();
    $invoiceCountBefore = Invoice::query()->count();

    foreach (SalesReportType::cases() as $type) {
        $this->service->report($type, $asOf->subYear(), $asOf);
    }

    expect(JournalEntry::query()->count())->toBe($journalCountBefore)
        ->and(Invoice::query()->count())->toBe($invoiceCountBefore);
});
