<?php

declare(strict_types=1);

use App\Enums\SalesOpportunityStatus;
use App\Models\CustomerProfile;
use App\Models\CustomerVisit;
use App\Models\EmployeeProfile;
use App\Models\EmployeeVoiceNote;
use App\Models\SalesOpportunity;
use App\Models\VoiceNoteTranscription;
use App\Services\Sales\Exceptions\OpportunityNotQuotable;
use App\Services\Sales\QuotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function approvedOpportunityFor(CustomerProfile $customer, ?EmployeeProfile $employee = null): SalesOpportunity
{
    $employee ??= EmployeeProfile::factory()->create();
    $visit = CustomerVisit::factory()->for($customer, 'customer')->for($employee, 'employee')->create();
    $voiceNote = EmployeeVoiceNote::factory()->for($visit, 'customerVisit')->for($employee, 'employee')->create();
    $transcription = VoiceNoteTranscription::factory()->for($voiceNote, 'employeeVoiceNote')->create();

    return SalesOpportunity::factory()
        ->for($transcription, 'transcription')
        ->create(['status' => SalesOpportunityStatus::Approved, 'summary' => 'Customer wants 10 units of the new model.']);
}

it('creates a draft quotation from an approved opportunity, carrying the customer and note across (FR-025)', function (): void {
    $customer = CustomerProfile::factory()->create();
    $employee = EmployeeProfile::factory()->create();
    $opportunity = approvedOpportunityFor($customer, $employee);

    $quotation = app(QuotationService::class)->createFromOpportunity($opportunity);

    expect($quotation->customer_id)->toBe($customer->getKey())
        ->and($quotation->employee_id)->toBe($employee->getKey())
        ->and($quotation->sales_opportunity_id)->toBe($opportunity->getKey())
        ->and($quotation->notes)->toBe('Customer wants 10 units of the new model.')
        ->and($opportunity->quotation()->exists())->toBeTrue();
});

it('refuses to quote an opportunity that is not approved', function (): void {
    $customer = CustomerProfile::factory()->create();
    $opportunity = approvedOpportunityFor($customer);
    $opportunity->update(['status' => SalesOpportunityStatus::Draft]);

    app(QuotationService::class)->createFromOpportunity($opportunity);
})->throws(OpportunityNotQuotable::class);

it('refuses to quote an approved opportunity a second time', function (): void {
    $customer = CustomerProfile::factory()->create();
    $opportunity = approvedOpportunityFor($customer);

    app(QuotationService::class)->createFromOpportunity($opportunity);

    app(QuotationService::class)->createFromOpportunity($opportunity);
})->throws(OpportunityNotQuotable::class);
