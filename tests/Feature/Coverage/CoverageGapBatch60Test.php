<?php

declare(strict_types=1);

use App\Enums\MaintenanceBillingType;
use App\Enums\MaintenanceStatus;
use App\Enums\PaymentLinkStatus;
use App\Enums\QuotationStatus;
use App\Enums\WarrantyClaimDecision;
use App\Enums\WarrantyCoverageSource;
use App\Enums\WarrantyLineCategory;
use App\Models\CustomerProfile;
use App\Models\MaintenanceCoverageLine;
use App\Models\MaintenanceLabourEntry;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceTask;
use App\Models\MaintenanceThirdPartyCost;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\ServiceRecordPart;
use App\Models\Ticket;
use App\Models\TicketPaymentLink;
use App\Models\User;
use App\Services\Payments\PaymentService;
use App\Services\Support\Exceptions\InvalidBillingTransition;
use App\Services\Support\MaintenanceBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
    $this->paymentMethod = configurePaymentAccounting(taxPercent: 10.0);
});

function invokeMaintenanceBilling(string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(MaintenanceBillingService::class, $method)
        ->invoke(app(MaintenanceBillingService::class), ...$arguments);
}

it('covers ticket-settled accounting guards for missing and unposted payments', function (): void {
    $actor = User::factory()->admin()->create();

    $ticketWithoutLink = Ticket::factory()->chargeable()->create();
    $recordWithoutLink = MaintenanceRecord::factory()->for($ticketWithoutLink)->create([
        'customer_id' => $ticketWithoutLink->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled(
        $recordWithoutLink,
        $actor,
        'Collected elsewhere.',
    ))->toThrow(ValidationException::class, 'does not have a settled support payment');

    $ticket = Ticket::factory()->chargeable()->create();
    TicketPaymentLink::factory()->for($ticket)->create([
        'status' => PaymentLinkStatus::Settled,
        'amount' => '50.00',
        'currency' => 'AED',
        'payment_id' => null,
    ]);
    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled(
        $record,
        $actor,
        'Legacy link without accounting payment.',
    ))->toThrow(ValidationException::class, 'has not been posted to accounting');
});

it('covers invoice guards for missing pending and rejected quotation chains', function (): void {
    $actor = User::factory()->admin()->create();

    $make = function () use ($actor): MaintenanceRecord {
        $record = MaintenanceRecord::factory()->create([
            'status' => MaintenanceStatus::Closed,
            'billing_type' => MaintenanceBillingType::Quoted,
            'coverage_decision' => WarrantyClaimDecision::Rejected,
        ]);
        MaintenanceCoverageLine::factory()->for($record)->create([
            'description' => 'Customer repair',
            'amount_minor' => 10000,
            'coverage_percent' => 0,
            'covered_amount_minor' => 0,
            'customer_amount_minor' => 10000,
            'coverage_source' => WarrantyCoverageSource::CustomerPaid,
            'decided_by' => $actor->id,
        ]);

        return $record;
    };

    $missing = $make();
    expect(fn () => app(MaintenanceBillingService::class)->createInvoice($missing, $actor))
        ->toThrow(ValidationException::class, 'quotation is missing');

    $draftRecord = $make();
    $draft = Quotation::factory()->create([
        'customer_id' => $draftRecord->customer_id,
        'status' => QuotationStatus::Draft,
        'grand_total' => '110.00',
    ]);
    $draftRecord->forceFill(['quotation_id' => $draft->id])->save();

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice($draftRecord->refresh(), $actor))
        ->toThrow(ValidationException::class, 'latest customer quotation must be decided');

    $rejectedRecord = $make();
    $rejected = Quotation::factory()->create([
        'customer_id' => $rejectedRecord->customer_id,
        'status' => QuotationStatus::Rejected,
        'grand_total' => '110.00',
    ]);
    $rejectedRecord->forceFill(['quotation_id' => $rejected->id])->save();

    expect(fn () => app(MaintenanceBillingService::class)->createInvoice($rejectedRecord->refresh(), $actor))
        ->toThrow(ValidationException::class, 'must be accepted before creating the final invoice');
});

it('covers quotation-chain traversal including accepted parent and cycle protection', function (): void {
    $customer = CustomerProfile::factory()->create();
    $accepted = Quotation::factory()->accepted()->create([
        'customer_id' => $customer->id,
        'grand_total' => '100.00',
    ]);
    $rejected = Quotation::factory()->create([
        'customer_id' => $customer->id,
        'status' => QuotationStatus::Rejected,
        'requoted_from_id' => $accepted->id,
    ]);

    expect(invokeMaintenanceBilling('latestAcceptedQuotation', $rejected)?->is($accepted))->toBeTrue()
        ->and(invokeMaintenanceBilling('latestAcceptedQuotation', new Quotation))->toBeNull();

    $first = Quotation::factory()->create([
        'customer_id' => $customer->id,
        'status' => QuotationStatus::Rejected,
    ]);
    $second = Quotation::factory()->create([
        'customer_id' => $customer->id,
        'status' => QuotationStatus::Rejected,
        'requoted_from_id' => $first->id,
    ]);
    $first->forceFill(['requoted_from_id' => $second->id])->save();

    expect(invokeMaintenanceBilling('latestAcceptedQuotation', $first->refresh()))->toBeNull();
});

it('builds actual customer responsibility from covered parts labour third-party and static lines', function (): void {
    $actor = User::factory()->admin()->create();
    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);

    $task = MaintenanceTask::factory()->for($record)->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => '100.00',
        'min_price' => null,
    ]);
    $part = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '2.000000',
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Half covered part',
        'source_type' => ServiceRecordPart::class,
        'source_id' => $part->id,
        'amount_minor' => 20000,
        'coverage_percent' => 50,
        'covered_amount_minor' => 10000,
        'customer_amount_minor' => 10000,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
        'decided_by' => $actor->id,
    ]);

    $fullyCoveredPart = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '1.000000',
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Fully covered part',
        'source_type' => ServiceRecordPart::class,
        'source_id' => $fullyCoveredPart->id,
        'amount_minor' => 10000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 10000,
        'customer_amount_minor' => 0,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
        'decided_by' => $actor->id,
    ]);

    MaintenanceLabourEntry::factory()->for($record)->create([
        'total_cost_minor' => 10000,
        'minutes' => 60,
        'hourly_rate_minor' => 10000,
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'category' => WarrantyLineCategory::Labour,
        'description' => 'Half covered labour',
        'source_type' => 'maintenance_labour',
        'source_id' => null,
        'amount_minor' => 10000,
        'coverage_percent' => 50,
        'covered_amount_minor' => 5000,
        'customer_amount_minor' => 5000,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
        'decided_by' => $actor->id,
    ]);

    $thirdParty = MaintenanceThirdPartyCost::factory()->for($record)->create([
        'description' => 'External repair',
        'amount_minor' => 6000,
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Fully covered external repair',
        'source_type' => MaintenanceThirdPartyCost::class,
        'source_id' => $thirdParty->id,
        'amount_minor' => 6000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 6000,
        'customer_amount_minor' => 0,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
        'decided_by' => $actor->id,
    ]);

    MaintenanceThirdPartyCost::factory()->for($record)->create([
        'description' => 'Unassessed courier',
        'amount_minor' => 4000,
    ]);
    MaintenanceCoverageLine::factory()->for($record)->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Manual customer adjustment',
        'source_type' => null,
        'source_id' => null,
        'amount_minor' => 3000,
        'coverage_percent' => 0,
        'covered_amount_minor' => 0,
        'customer_amount_minor' => 3000,
        'coverage_source' => WarrantyCoverageSource::CustomerPaid,
        'decided_by' => $actor->id,
    ]);

    $lines = invokeMaintenanceBilling('actualCustomerResponsibilityLines', $record->refresh());

    expect($lines)->toHaveCount(4)
        ->and(collect($lines)->pluck('description')->filter()->values()->all())
        ->toContain('Labour', 'Unassessed courier', 'Manual customer adjustment')
        ->and(invokeMaintenanceBilling('linesTotal', $lines))->toBeGreaterThan(0.0);
});

it('covers actual-customer-responsibility empty assessment fallbacks', function (): void {
    $fullyCovered = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::FullyCovered,
    ]);
    expect(invokeMaintenanceBilling('actualCustomerResponsibilityLines', $fullyCovered))->toBe([]);

    $actor = User::factory()->create();
    $rejected = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);
    MaintenanceLabourEntry::factory()->for($rejected)->create([
        'employee_id' => $actor->id,
        'created_by' => $actor->id,
        'minutes' => 60,
        'hourly_rate_minor' => 10000,
        'total_cost_minor' => 10000,
    ]);
    MaintenanceThirdPartyCost::factory()->for($rejected)->create([
        'created_by' => $actor->id,
        'description' => 'Courier',
        'amount_minor' => 5000,
    ]);

    $lines = invokeMaintenanceBilling('actualCustomerResponsibilityLines', $rejected->refresh());
    expect($lines)->toHaveCount(2);
});

it('covers billable quotable and line helper branches', function (): void {
    $open = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Open,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);
    expect(fn (): mixed => invokeMaintenanceBilling('assertBillable', $open))->toThrow(InvalidBillingTransition::class)
        ->and(fn (): mixed => invokeMaintenanceBilling('assertQuotable', $open))->toThrow(ValidationException::class);

    $covered = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::WarrantyCovered,
    ]);
    expect(fn (): mixed => invokeMaintenanceBilling('assertBillable', $covered))->toThrow(InvalidBillingTransition::class);

    $settled = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::TicketSettled,
    ]);
    expect(fn (): mixed => invokeMaintenanceBilling('assertBillable', $settled))->toThrow(InvalidBillingTransition::class)
        ->and(fn (): mixed => invokeMaintenanceBilling('assertQuotable', $settled))->toThrow(InvalidBillingTransition::class);

    foreach ([
        WarrantyClaimDecision::FullyCovered,
        WarrantyClaimDecision::Goodwill,
        WarrantyClaimDecision::ThirdPartyWarranty,
        WarrantyClaimDecision::ServiceContract,
    ] as $decision) {
        $record = MaintenanceRecord::factory()->create([
            'status' => MaintenanceStatus::Closed,
            'billing_type' => MaintenanceBillingType::Unbilled,
            'coverage_decision' => $decision,
        ]);
        expect(fn (): mixed => invokeMaintenanceBilling('assertQuotable', $record))->toThrow(ValidationException::class);
    }

    $link = TicketPaymentLink::factory()->create(['amount' => '110.00', 'currency' => 'AED']);
    $line = invokeMaintenanceBilling('ticketFeeInvoiceLine', $link);
    expect($line['unit_price'])->toBe(100.0)
        ->and($line['tax_amount'])->toBe(10.0)
        ->and(invokeMaintenanceBilling('linesTotal', [$line]))->toBe(110.0);
});

it('covers third-party and frozen responsibility line fallbacks', function (): void {
    $actor = User::factory()->create();

    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);
    MaintenanceThirdPartyCost::factory()->for($record)->create([
        'created_by' => $actor->id,
        'description' => 'Zero cost',
        'amount_minor' => 0,
    ]);
    MaintenanceThirdPartyCost::factory()->for($record)->create([
        'created_by' => $actor->id,
        'description' => 'Billable cost',
        'amount_minor' => 2500,
    ]);

    expect(invokeMaintenanceBilling('thirdPartyLines', $record->refresh()))->toHaveCount(1)
        ->and(invokeMaintenanceBilling('customerResponsibilityLines', $record->refresh()))->not->toBe([]);

    foreach ([
        WarrantyClaimDecision::PartiallyCovered,
        WarrantyClaimDecision::FullyCovered,
        WarrantyClaimDecision::Goodwill,
        WarrantyClaimDecision::ThirdPartyWarranty,
        WarrantyClaimDecision::ServiceContract,
    ] as $decision) {
        $empty = MaintenanceRecord::factory()->create(['coverage_decision' => $decision]);
        expect(invokeMaintenanceBilling('customerResponsibilityLines', $empty))->toBe([]);
    }

    $pending = MaintenanceRecord::factory()->create(['coverage_decision' => WarrantyClaimDecision::PendingDiagnosis]);
    expect(invokeMaintenanceBilling('customerResponsibilityLines', $pending))->toBe([]);
});

it('rejects ticket-settled billing when the posted deposit cannot fully cover the service invoice', function (): void {
    $actor = User::factory()->admin()->create();
    $ticket = Ticket::factory()->chargeable()->create();

    $payment = app(PaymentService::class)->createDraft($actor, [
        'customer_id' => $ticket->customer_id,
        'payment_method_id' => $this->paymentMethod->getKey(),
        'amount' => 40.00,
        'currency' => 'AED',
        'payment_date' => now()->toDateString(),
        'external_reference' => 'PARTIAL-TICKET-DEPOSIT',
    ]);
    $posted = app(PaymentService::class)->post($actor, $payment, []);

    TicketPaymentLink::factory()->for($ticket)->create([
        'status' => PaymentLinkStatus::Settled,
        'amount' => '50.00',
        'currency' => 'AED',
        'payment_id' => $posted->getKey(),
        'settled_by' => $actor->getKey(),
        'settled_at' => now(),
    ]);

    $record = MaintenanceRecord::factory()->for($ticket)->create([
        'customer_id' => $ticket->customer_id,
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Unbilled,
    ]);

    expect(fn () => app(MaintenanceBillingService::class)->markTicketSettled(
        $record,
        $actor,
        'Only part of the fee is available as a deposit.',
    ))->toThrow(ValidationException::class, 'could not be fully applied');
});

it('rejects an unnecessary revised quotation when the accepted ceiling still covers actual work', function (): void {
    $actor = User::factory()->admin()->create();
    $record = MaintenanceRecord::factory()->create([
        'status' => MaintenanceStatus::Closed,
        'billing_type' => MaintenanceBillingType::Quoted,
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);

    MaintenanceLabourEntry::factory()->for($record)->create([
        'employee_id' => $actor->getKey(),
        'created_by' => $actor->getKey(),
        'minutes' => 60,
        'hourly_rate_minor' => 1000,
        'total_cost_minor' => 1000,
    ]);

    $accepted = Quotation::factory()->accepted()->create([
        'customer_id' => $record->customer_id,
        'grand_total' => '100.00',
    ]);
    $record->forceFill(['quotation_id' => $accepted->getKey()])->save();

    expect(fn () => app(MaintenanceBillingService::class)->createQuotation($record->refresh(), $actor))
        ->toThrow(ValidationException::class, 'already covers the current customer responsibility');
});

it('covers reversed and unassessed actual part and labour branches', function (): void {
    $actor = User::factory()->create();
    $record = MaintenanceRecord::factory()->create([
        'coverage_decision' => WarrantyClaimDecision::Rejected,
    ]);

    MaintenanceCoverageLine::factory()->for($record)->create([
        'category' => WarrantyLineCategory::Other,
        'description' => 'Assessment marker',
        'source_type' => null,
        'source_id' => null,
        'amount_minor' => 1000,
        'coverage_percent' => 100,
        'covered_amount_minor' => 1000,
        'customer_amount_minor' => 0,
        'coverage_source' => WarrantyCoverageSource::SellerWarranty,
        'decided_by' => $actor->id,
    ]);

    $task = MaintenanceTask::factory()->for($record)->create();
    $variant = ProductVariant::factory()->create(['base_price' => '25.00', 'min_price' => null]);

    $reversed = ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '1.000000',
    ]);
    $reversed->forceFill(['reversed_at' => now()])->saveQuietly();

    ServiceRecordPart::factory()->for($task, 'maintenanceTask')->create([
        'product_variant_id' => $variant->id,
        'quantity' => '2.000000',
    ]);

    MaintenanceLabourEntry::factory()->for($record)->create([
        'employee_id' => $actor->id,
        'created_by' => $actor->id,
        'minutes' => 60,
        'hourly_rate_minor' => 5000,
        'total_cost_minor' => 5000,
    ]);

    $lines = invokeMaintenanceBilling('actualCustomerResponsibilityLines', $record->refresh());

    expect($lines)->toHaveCount(2)
        ->and(collect($lines)->pluck('description')->filter()->values()->all())->toContain('Labour');
});
