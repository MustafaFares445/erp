<?php

declare(strict_types=1);

use App\Data\Crm\InteractionData;
use App\Data\Crm\LeadData;
use App\Data\Sales\OpportunityData;
use App\Enums\CampaignChannel;
use App\Enums\CustomerApprovalStatus;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\User;
use App\Services\Crm\LeadConversionService;
use App\Services\Crm\LeadService;
use App\Services\Sales\OpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function crmRemediationCurrency(): void
{
    Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true],
    );
}

function qualifiedCrmRemediationLead(User $actor, string $email): Lead
{
    $service = app(LeadService::class);
    $lead = $service->create(new LeadData(
        source: LeadSource::Website,
        firstName: 'CRM',
        lastName: 'Prospect',
        companyName: 'CRM Prospect LLC',
        email: $email,
        phone: '+971500000001',
    ), $actor);

    $service->logAndAdvance(
        $lead,
        new InteractionData(
            subject: $lead,
            type: InteractionType::Call,
            direction: InteractionDirection::Outbound,
            occurredAt: now()->subHour(),
            summary: 'Initial CRM contact',
        ),
        LeadStatus::Contacted,
        $actor,
    );

    $lead = $lead->refresh();

    $service->logAndAdvance(
        $lead,
        new InteractionData(
            subject: $lead,
            type: InteractionType::Meeting,
            direction: InteractionDirection::Outbound,
            occurredAt: now(),
            summary: 'Qualified CRM opportunity',
        ),
        LeadStatus::Qualified,
        $actor,
    );

    return $lead->refresh();
}

it('approves a converted lead customer and backfills existing lead opportunities', function (): void {
    crmRemediationCurrency();
    $actor = User::factory()->admin()->create();
    $lead = qualifiedCrmRemediationLead($actor, 'crm-remediation@example.test');

    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-CRM-REMEDIATION',
        'name' => 'CRM remediation campaign',
        'channel' => CampaignChannel::Email,
        'segment_criteria' => [],
        'created_by' => $actor->getKey(),
    ])->save();
    $lead->forceFill(['campaign_id' => $campaign->getKey()])->save();

    $opportunity = app(OpportunityService::class)->create(new OpportunityData(
        summary: 'Lead opportunity before conversion',
        leadId: (int) $lead->getKey(),
    ), $actor);

    expect($opportunity->customer_id)->toBeNull()
        ->and($opportunity->campaign_id)->toBe($campaign->getKey());

    $customer = app(LeadConversionService::class)->convert($lead, [
        'name' => 'CRM Prospect',
        'username' => 'crm-prospect-remediation',
        'email' => 'crm-login-remediation@example.test',
        'password' => Str::password(16),
        'contact_is_self' => true,
        'company_name' => 'CRM Prospect LLC',
        'company_email' => 'crm-remediation@example.test',
        'company_phone' => '+971500000001',
        'address' => 'CRM Street',
        'country' => 'United Arab Emirates',
        'city' => 'Dubai',
        'latitude' => 25.2048,
        'longitude' => 55.2708,
    ], $actor);

    expect($customer->approval_status)->toBe(CustomerApprovalStatus::Approved)
        ->and($customer->is_active)->toBeTrue()
        ->and($opportunity->refresh()->customer_id)->toBe($customer->getKey())
        ->and($opportunity->resolvedCustomer()?->getKey())->toBe($customer->getKey());
});

it('rejects mismatched lead and customer pairs on an opportunity', function (): void {
    crmRemediationCurrency();
    $actor = User::factory()->admin()->create();
    $lead = app(LeadService::class)->create(new LeadData(
        source: LeadSource::Referral,
        firstName: 'Party',
        lastName: 'Integrity',
        email: 'party-integrity@example.test',
    ), $actor);
    $customer = CustomerProfile::factory()->create();

    expect(fn () => app(OpportunityService::class)->create(new OpportunityData(
        summary: 'Invalid unconverted pair',
        customerId: (int) $customer->getKey(),
        leadId: (int) $lead->getKey(),
    ), $actor))->toThrow(ValidationException::class);

    $lead->forceFill([
        'status' => LeadStatus::Converted,
        'converted_customer_id' => $customer->getKey(),
        'converted_at' => now(),
    ])->save();

    $matching = app(OpportunityService::class)->create(new OpportunityData(
        summary: 'Valid converted pair',
        customerId: (int) $customer->getKey(),
        leadId: (int) $lead->getKey(),
    ), $actor);

    expect($matching->customer_id)->toBe($customer->getKey());

    $otherCustomer = CustomerProfile::factory()->create();

    expect(fn () => app(OpportunityService::class)->create(new OpportunityData(
        summary: 'Mismatched converted pair',
        customerId: (int) $otherCustomer->getKey(),
        leadId: (int) $lead->getKey(),
    ), $actor))->toThrow(ValidationException::class);
});
