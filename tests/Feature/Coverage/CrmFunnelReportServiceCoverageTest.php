<?php

declare(strict_types=1);

use App\Data\Crm\LeadData;
use App\Enums\CampaignChannel;
use App\Enums\CampaignResponseType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignResponse;
use App\Models\Currency;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Crm\CrmFunnelReportService;
use App\Services\Crm\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function crmFunnelCoverageLead(User $actor, string $email, LeadSource $source = LeadSource::Website): Lead
{
    return app(LeadService::class)->create(new LeadData(source: $source, firstName: 'Coverage', email: $email), $actor);
}
it('reports CRM lead source stage and pipeline age', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $old = crmFunnelCoverageLead($actor, 'old@example.test', LeadSource::Website);
    $new = crmFunnelCoverageLead($actor, 'new@example.test', LeadSource::Referral);
    $converted = crmFunnelCoverageLead($actor, 'converted@example.test', LeadSource::Website);

    $old->forceFill(['created_at' => now()->subDays(6)])->saveQuietly();
    $new->forceFill(['status' => LeadStatus::Contacted, 'created_at' => now()->subDays(2)])->saveQuietly();
    $converted->forceFill(['status' => LeadStatus::Converted, 'converted_at' => now()])->saveQuietly();

    $service = app(CrmFunnelReportService::class);
    $bySource = $service->bySource();
    $byStage = $service->byStage();
    $pipeline = $service->pipelineAge();

    expect($bySource)->toHaveCount(2)
        ->and($bySource->sum('lead_count'))->toBe(3)
        ->and($bySource->sum('converted_count'))->toBe(1)
        ->and($byStage->sum('lead_count'))->toBe(3)
        ->and($pipeline->sum('lead_count'))->toBe(2)
        ->and($pipeline->pluck('status')->all())->toContain(LeadStatus::New->value, LeadStatus::Contacted->value);
});
it('reports campaign recipients interested responses and attributed leads', function (): void {
    Gate::before(static fn (): bool => true);
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-FUNNEL-001',
        'name' => 'Funnel coverage',
        'channel' => CampaignChannel::Email,
        'segment_criteria' => [],
        'created_by' => $actor->getKey(),
    ])->save();
    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => $customer->getMorphClass(),
        'recipient_id' => $customer->getKey(),
        'email' => $customer->email,
    ]);
    CampaignResponse::query()->create([
        'campaign_recipient_id' => $recipient->getKey(),
        'type' => CampaignResponseType::Interested,
        'occurred_at' => now(),
        'payload' => [],
    ]);
    $lead = crmFunnelCoverageLead($actor, 'campaign-lead@example.test');
    $lead->forceFill(['campaign_id' => $campaign->getKey()])->saveQuietly();

    $rows = app(CrmFunnelReportService::class)->byCampaign();
    $row = $rows->firstWhere('campaign_number', 'CMP-FUNNEL-001');

    expect($row)->not->toBeNull()
        ->and($row['recipients_count'])->toBe(1)
        ->and($row['interested_count'])->toBe(1)
        ->and($row['leads_count'])->toBe(1);
});

it('reports campaign-attributed collected revenue from posted payments', function (): void {
    Gate::before(static fn (): bool => true);
    Currency::query()->firstOrCreate(['code' => 'AED'], ['name' => 'UAE Dirham', 'is_active' => true, 'is_default' => true]);
    $actor = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-REV-001', 'name' => 'Revenue campaign',
        'channel' => CampaignChannel::Email, 'segment_criteria' => [], 'created_by' => $actor->getKey(),
    ])->save();
    $lead = crmFunnelCoverageLead($actor, 'revenue-lead@example.test');
    $lead->forceFill([
        'campaign_id' => $campaign->getKey(),
        'status' => LeadStatus::Converted,
        'converted_customer_id' => $customer->getKey(),
        'converted_at' => now(),
    ])->saveQuietly();

    $invoice = Invoice::factory()->for($customer, 'customer')->create(['issued_at' => now()]);
    $method = PaymentMethod::factory()->create();
    $payment = Payment::query()->create([
        'payment_number' => 'PAY-FUNNEL-001',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '75.00',
        'currency' => 'AED',
        'payment_date' => today(),
        'status' => PaymentStatus::Posted,
        'posted_at' => now(),
    ]);
    $payment->allocations()->create(['invoice_id' => $invoice->getKey(), 'amount' => '75.00']);

    $row = app(CrmFunnelReportService::class)->attributedRevenue()->firstWhere('campaign_id', $campaign->getKey());
    expect($row)->not->toBeNull()
        ->and($row['collected_amount'])->toBe(75.0);
});
it('normalizes CRM funnel scalar values defensively', function (): void {
    $service = app(CrmFunnelReportService::class);

    $string = new ReflectionMethod($service, 'stringValue');

    $integer = new ReflectionMethod($service, 'intValue');

    $float = new ReflectionMethod($service, 'floatValue');

    expect($string->invoke($service, LeadSource::Website))->toBe(LeadSource::Website->value)
        ->and($string->invoke($service, 'plain'))->toBe('plain')
        ->and($string->invoke($service, 123))->toBe('')
        ->and($integer->invoke($service, '12'))->toBe(12)
        ->and($integer->invoke($service, []))->toBe(0)
        ->and($float->invoke($service, '12.5'))->toBe(12.5)
        ->and($float->invoke($service, new stdClass))->toBe(0.0);
});
