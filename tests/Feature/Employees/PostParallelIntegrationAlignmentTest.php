<?php

declare(strict_types=1);

use App\Enums\PlanTaskStatus;
use App\Enums\ProductStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOpportunityStatus;
use App\Enums\SerializedCustodyType;
use App\Models\AiKeywordRule;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use App\Models\Order;
use App\Models\PlanTask;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOpportunity;
use App\Models\SalesPlan;
use App\Models\SerializedInventoryUnit;
use App\Models\VoiceNoteTranscription;
use App\Services\Employees\FollowUpCreationService;
use App\Services\Employees\KeywordDetectionService;
use App\Services\Employees\PerformanceScoringService;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Currency::query()->updateOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
});

function integrationVisitContext(): array
{
    $employee = EmployeeProfile::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $plan = SalesPlan::factory()->create([
        'employee_id' => $employee->getKey(),
        'task_weight' => 20,
        'visit_weight' => 25,
        'schedule_weight' => 20,
        'work_time_weight' => 15,
        'opportunity_weight' => 20,
    ]);
    $task = PlanTask::factory()->create([
        'sales_plan_id' => $plan->getKey(),
        'customer_id' => $customer->getKey(),
        'status' => PlanTaskStatus::Completed,
        'completed_at' => now(),
    ]);
    $visit = CustomerVisit::factory()->completed()->create([
        'employee_id' => $employee->getKey(),
        'plan_task_id' => $task->getKey(),
        'customer_id' => $customer->getKey(),
    ]);

    return [$employee, $customer, $plan, $task, $visit];
}

function opportunityForVisit(CustomerVisit $visit, EmployeeProfile $employee, SalesOpportunityStatus $status): SalesOpportunity
{
    $voice = EmployeeVoiceNote::factory()->create([
        'customer_visit_id' => $visit->getKey(),
        'employee_id' => $employee->getKey(),
    ]);
    $transcription = VoiceNoteTranscription::factory()->create([
        'employee_voice_note_id' => $voice->getKey(),
    ]);

    return SalesOpportunity::factory()->create([
        'voice_note_transcription_id' => $transcription->getKey(),
        'source_visit_id' => $visit->getKey(),
        'source_voice_note_id' => $voice->getKey(),
        'customer_id' => $visit->customer_id,
        'status' => $status,
    ]);
}

it('counts only reviewed and accepted opportunities in employee performance', function (): void {
    [$employee, $customer, $plan, $task, $visit] = integrationVisitContext();

    opportunityForVisit($visit, $employee, SalesOpportunityStatus::Draft);
    opportunityForVisit($visit, $employee, SalesOpportunityStatus::Approved);
    opportunityForVisit($visit, $employee, SalesOpportunityStatus::Rejected);

    $score = app(PerformanceScoringService::class)->scoreForPlan($plan);

    expect($score->raw_values['detected_opportunities'])->toBe(1)
        ->and((float) $score->opportunity_score)->toBe(20.0)
        ->and($score->calculation_breakdown['potential_sales_opportunities']['rule'])
        ->toContain('Reviewed and accepted');
});

it('allows a visit to reference only equipment owned by that visit customer', function (): void {
    $owner = CustomerProfile::factory()->create();
    $other = CustomerProfile::factory()->create();
    $employee = EmployeeProfile::factory()->create();
    $unit = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $owner->getKey(),
    ]);

    $visit = CustomerVisit::factory()->create([
        'employee_id' => $employee->getKey(),
        'customer_id' => $owner->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]);

    expect($visit->equipment->is($unit))->toBeTrue();

    expect(fn () => CustomerVisit::factory()->create([
        'employee_id' => $employee->getKey(),
        'customer_id' => $other->getKey(),
        'serialized_inventory_unit_id' => $unit->getKey(),
    ]))->toThrow(DomainException::class);
});

it('carries visit sales and equipment provenance into an automatic follow-up task', function (): void {
    [$employee, $customer, $plan, $sourceTask, $visit] = integrationVisitContext();
    $equipment = SerializedInventoryUnit::factory()->create([
        'custody_type' => SerializedCustodyType::Customer,
        'custody_reference_type' => 'customer',
        'custody_reference_id' => $customer->getKey(),
    ]);
    $visit->update([
        'serialized_inventory_unit_id' => $equipment->getKey(),
        'follow_up_required' => true,
        'follow_up_date' => today()->addDay(),
        'follow_up_note' => 'Confirm the commercial next step.',
    ]);

    $opportunity = opportunityForVisit($visit, $employee, SalesOpportunityStatus::Approved);
    $quotation = Quotation::factory()->create([
        'customer_id' => $customer->getKey(),
        'employee_id' => $employee->getKey(),
        'sales_opportunity_id' => $opportunity->getKey(),
        'status' => QuotationStatus::Accepted,
    ]);
    $order = Order::factory()->create([
        'customer_id' => $customer->getKey(),
        'quotation_id' => $quotation->getKey(),
    ]);
    $quotation->update([
        'converted_order_id' => $order->getKey(),
        'status' => QuotationStatus::ConvertedToOrder,
    ]);

    $followUp = app(FollowUpCreationService::class)->createForVisit($visit->refresh());

    expect($followUp->source_visit_id)->toBe($visit->getKey())
        ->and($followUp->sales_opportunity_id)->toBe($opportunity->getKey())
        ->and($followUp->quotation_id)->toBe($quotation->getKey())
        ->and($followUp->order_id)->toBe($order->getKey())
        ->and($followUp->serialized_inventory_unit_id)->toBe($equipment->getKey());
});

it('resolves employee quotation pricing and sales uom from customer commercial rules', function (): void {
    $employee = EmployeeProfile::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->create([
        'base_price' => 120,
        'min_price' => 60,
        'status' => ProductStatus::Active,
    ]);
    $variant->product->update(['status' => ProductStatus::Active]);

    $priceList = PriceList::query()->create([
        'name' => 'Clinic AED',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);
    $customer->update([
        'default_currency_code' => 'AED',
        'default_price_list_id' => $priceList->getKey(),
    ]);
    $break = PriceListItem::query()->create([
        'price_list_id' => $priceList->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->getKey(),
        'minimum_quantity' => '10.000000',
        'price' => '90.00',
        'is_active' => true,
    ]);

    $quotation = app(QuotationService::class)->create([
        'customer_id' => $customer->getKey(),
        'employee_id' => $employee->getKey(),
        'issue_date' => today(),
    ], [[
        'product_variant_id' => $variant->getKey(),
        'quantity' => 10,
    ]]);

    $line = $quotation->lines()->sole();

    expect((float) $line->unit_price)->toBe(90.0)
        ->and($line->unit_id)->toBe($variant->unit_id)
        ->and($line->resolved_price_list_id)->toBe($priceList->getKey())
        ->and($line->resolved_price_list_item_id)->toBe($break->getKey())
        ->and($line->base_quantity)->not->toBeNull();
});

it('allows AI detection to remain product-level when the transcript does not identify a variant', function (): void {
    [$employee, $customer, $plan, $task, $visit] = integrationVisitContext();
    $product = ProductVariant::factory()->create()->product;
    $rule = AiKeywordRule::query()->create([
        'keyword' => 'implant',
        'product_id' => $product->getKey(),
        'product_variant_id' => null,
        'is_active' => true,
    ]);
    $voice = EmployeeVoiceNote::factory()->create([
        'customer_visit_id' => $visit->getKey(),
        'employee_id' => $employee->getKey(),
    ]);
    $transcription = VoiceNoteTranscription::factory()->create([
        'employee_voice_note_id' => $voice->getKey(),
        'transcript' => 'The clinic is interested in a new implant solution.',
    ]);

    $detected = app(KeywordDetectionService::class)->detect($transcription)->sole();

    expect($detected->ai_keyword_rule_id)->toBe($rule->getKey())
        ->and($detected->detected_product_id)->toBe($product->getKey())
        ->and($detected->detected_product_variant_id)->toBeNull()
        ->and($detected->status)->toBe(SalesOpportunityStatus::Draft);
});
