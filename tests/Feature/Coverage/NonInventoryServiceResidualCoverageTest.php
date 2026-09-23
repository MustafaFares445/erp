<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Enums\ProductStatus;
use App\Models\Campaign;
use App\Models\CustomerProfile;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\Supplier;
use App\Models\SupplierProductSupport;
use App\Models\User;
use App\Services\Crm\CampaignDispatchService;
use App\Services\Crm\CrmFunnelReportService;
use App\Services\Crm\CustomerQuotationRequestService;
use App\Services\Crm\Exceptions\InvalidCustomerQuotationRequestTransition;
use App\Services\Crm\InteractionService;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadService;
use App\Services\Purchasing\SupplierSupportResolver;
use App\Services\Reporting\SupplierComparisonReportService;
use App\Services\Sales\Exceptions\InvalidQuotationTransition;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use App\Services\Sales\OpportunityService;
use App\Services\Sales\PaymentTermService;
use App\Services\Sales\QuotationResponseService;
use App\Services\Sales\QuotationService;
use App\Services\Sales\SalesReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});
it('covers invalid CRM interaction subjects', function (): void {
    $actor = User::factory()->admin()->create();
    $product = Product::factory()->create();

    $data = new InteractionData(
        subject: $product,
        type: InteractionType::Call,
        direction: InteractionDirection::Outbound,
        occurredAt: now(),
        summary: 'Invalid CRM subject coverage',
    );

    expect(fn () => app(InteractionService::class)->log($data, $actor))
        ->toThrow(DomainException::class, 'lead or customer');
});

it('covers quotation response calls without any recorder', function (): void {
    $quotation = Quotation::factory()->sent()->create();
    $service = app(QuotationResponseService::class);

    expect(fn () => $service->accept($quotation, now(), null, null, null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->requestChanges(
            $quotation,
            now(),
            'Please revise.',
            null,
            null,
        ))->toThrow(InvalidArgumentException::class);
});
it('covers campaign dispatch from a terminal campaign state', function (): void {
    $actor = User::factory()->admin()->create();

    $campaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-RESIDUAL-001',
        'name' => 'Residual coverage campaign',
        'channel' => CampaignChannel::Other,
        'status' => CampaignStatus::Completed,
        'created_by' => $actor->getKey(),
    ]);

    expect(fn () => app(CampaignDispatchService::class)->dispatch($campaign, $actor))
        ->toThrow(DomainException::class, 'draft or scheduled');
});

it('covers invalid customer quotation request quantities and inactive variants', function (): void {
    $customer = CustomerProfile::factory()->create();
    $active = ProductVariant::factory()->create();

    expect(fn () => app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $active->getKey(), 'requested_quantity' => 0]],
    ))->toThrow(InvalidCustomerQuotationRequestTransition::class);

    $inactive = ProductVariant::factory()->create(['status' => ProductStatus::Inactive]);
    expect(fn () => app(CustomerQuotationRequestService::class)->submit(
        $customer,
        [['product_variant_id' => $inactive->getKey(), 'requested_quantity' => 1]],
    ))->toThrow(InvalidCustomerQuotationRequestTransition::class, 'not available');
});

it('covers lead conversion guards for already converted and duplicate-email leads', function (): void {
    $actor = User::factory()->admin()->create();
    $leads = app(LeadService::class);

    $alreadyConverted = $leads->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Converted',
        lastName: 'Lead',
        email: 'converted-lead@example.test',
    ), $actor);
    $customer = CustomerProfile::factory()->create();
    $alreadyConverted->forceFill([
        'status' => LeadStatus::Qualified,
        'converted_customer_id' => $customer->getKey(),
    ])->saveQuietly();

    expect(fn () => app(LeadConversionService::class)->convert(
        $alreadyConverted,
        [],
        $actor,
    ))->toThrow(DomainException::class, 'already been converted');
    $duplicate = $leads->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Duplicate',
        lastName: 'Lead',
        email: 'duplicate-lead@example.test',
    ), $actor);
    $duplicate->forceFill(['status' => LeadStatus::Qualified])->saveQuietly();

    CustomerProfile::factory()->create(['email' => 'duplicate-lead@example.test']);

    expect(fn () => app(LeadConversionService::class)->convert(
        $duplicate,
        [],
        $actor,
    ))->toThrow(DomainException::class, 'already exists');
});

it('covers opportunity close helpers when no actor can be resolved', function (): void {
    $opportunity = SalesOpportunity::factory()->manual()->create([
        'owner_id' => null,
        'stage' => OpportunityStage::Proposal,
    ]);
    $quotation = Quotation::factory()->create([
        'sales_opportunity_id' => $opportunity->getKey(),
        'decided_by' => null,
    ]);
    $service = app(OpportunityService::class);
    expect($service->closeWonFromQuotation($quotation)?->is($opportunity))->toBeTrue()
        ->and($service->closeLostOnQuotationRejection(
            $quotation->refresh(),
            'Coverage rejection',
        )?->is($opportunity))->toBeTrue();
});

it('covers an approved quotation opportunity without a customer', function (): void {
    $opportunity = SalesOpportunity::factory()->manual()->create([
        'customer_id' => null,
        'stage' => OpportunityStage::Proposal,
    ]);

    expect(fn () => app(QuotationService::class)->createFromOpportunity($opportunity))
        ->toThrow(OpportunityNotQuotable::class);
});

it('covers referenced payment-term deletion protection', function (): void {
    $term = PaymentTerm::factory()->create();
    Quotation::factory()->create(['payment_term_id' => $term->getKey()]);

    expect(fn () => app(PaymentTermService::class)->delete($term))
        ->toThrow(DomainException::class, 'quotation references it');
});
it('covers supplier support intersections with no common supplier', function (): void {
    $first = ProductVariant::factory()->create();
    $second = ProductVariant::factory()->create();
    $firstSupplier = Supplier::factory()->create();
    $secondSupplier = Supplier::factory()->create();

    SupplierProductSupport::factory()->create([
        'supplier_id' => $firstSupplier->getKey(),
        'product_variant_id' => $first->getKey(),
    ]);
    SupplierProductSupport::factory()->create([
        'supplier_id' => $secondSupplier->getKey(),
        'product_variant_id' => $second->getKey(),
    ]);

    expect(app(SupplierSupportResolver::class)->eligibleSupplierIds([
        $first->getKey(),
        $second->getKey(),
    ]))->toBe([]);
});

it('covers supplier-comparison integer filters', function (): void {
    $sql = app(SupplierComparisonReportService::class)->query([
        'supplier_id' => 1,
        'product_variant_id' => 2,
    ])->toSql();

    expect($sql)->toContain('supplier_id')
        ->and($sql)->toContain('product_variant_id');
});
it('covers even report medians and quotation not-expired exception construction', function (): void {
    $median = new ReflectionMethod(SalesReportService::class, 'median');

    expect($median->invoke(null, [1, 3]))->toBe(2.0)
        ->and(InvalidQuotationTransition::notExpired('QT-COVERAGE'))
        ->toBeInstanceOf(InvalidQuotationTransition::class);
});
it('covers CRM pipeline age for a historical row without a created timestamp', function (): void {
    $actor = User::factory()->admin()->create();
    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'Historical',
        lastName: 'Lead',
        email: 'historical-lead@example.test',
    ), $actor);

    DB::table('leads')->where('id', $lead->getKey())->update(['created_at' => null]);

    $row = app(CrmFunnelReportService::class)
        ->pipelineAge()
        ->firstWhere('status', LeadStatus::New->value);

    expect($row)->not->toBeNull()
        ->and($row['average_age_days'])->toBe(0.0);
});
