<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CrmReportType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\CrmReports\Pages\ViewCrmReports;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignResponse;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Crm\CrmFunnelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function createFunnelCoverageLead(User $actor, string $number, LeadSource $source, LeadStatus $status, array $extra = []): Lead
{
    $lead = new Lead;
    $lead->forceFill(array_merge([
        'lead_number' => $number,
        'source' => $source,
        'status' => $status,
        'company_name' => $number,
        'created_by' => $actor->getKey(),
    ], $extra))->save();

    return $lead;
}

it('reports CRM funnel rows across sources stages campaigns ages and attributed revenue', function (): void {
    $actor = User::factory()->create();
    $customer = CustomerProfile::factory()->create();
    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-FUNNEL-001',
        'name' => 'Funnel coverage',
        'channel' => CampaignChannel::Email,
        'created_by' => $actor->getKey(),
        'segment_criteria' => [],
    ])->save();

    createFunnelCoverageLead($actor, 'LEAD-FUNNEL-001', LeadSource::Website, LeadStatus::New, [
        'campaign_id' => $campaign->getKey(),
        'created_at' => now()->subDays(10),
    ]);
    createFunnelCoverageLead($actor, 'LEAD-FUNNEL-002', LeadSource::Referral, LeadStatus::Contacted, [
        'created_at' => now()->subDays(4),
    ]);
    $converted = createFunnelCoverageLead($actor, 'LEAD-FUNNEL-003', LeadSource::Website, LeadStatus::Converted, [
        'campaign_id' => $campaign->getKey(),
        'converted_customer_id' => $customer->getKey(),
        'converted_at' => now(),
    ]);

    $recipient = CampaignRecipient::query()->create([
        'campaign_id' => $campaign->getKey(),
        'recipient_type' => CustomerProfile::class,
        'recipient_id' => $customer->getKey(),
        'email' => $customer->email,
    ]);
    CampaignResponse::query()->create([
        'campaign_recipient_id' => $recipient->getKey(),
        'type' => 'interested',
        'occurred_at' => now(),
        'payload' => [],
    ]);

    $invoice = Invoice::factory()->for($customer, 'customer')->create([
        'issued_at' => now(),
        'total_amount' => '100.00',
    ]);
    $method = PaymentMethod::factory()->create();
    $paymentId = DB::table('payments')->insertGetId([
        'payment_number' => 'PAY-FUNNEL-001',
        'customer_id' => $customer->getKey(),
        'payment_method_id' => $method->getKey(),
        'amount' => '60.00',
        'currency' => 'AED',
        'payment_date' => today()->toDateString(),
        'status' => PaymentStatus::Posted->value,
        'posted_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('payment_allocations')->insert([
        'payment_id' => $paymentId,
        'invoice_id' => $invoice->getKey(),
        'amount' => '60.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(CrmFunnelReportService::class);
    $sourceRows = $service->bySource();
    $stageRows = $service->byStage();
    $campaignRows = $service->byCampaign();
    $ageRows = $service->pipelineAge();
    $revenueRows = $service->attributedRevenue();

    expect($sourceRows->sum('lead_count'))->toBe(3)
        ->and($stageRows->sum('lead_count'))->toBe(3)
        ->and($campaignRows)->toHaveCount(1)
        ->and($campaignRows->first()['interested_count'])->toBe(1)
        ->and($campaignRows->first()['leads_count'])->toBe(2)
        ->and($ageRows->sum('lead_count'))->toBe(2)
        ->and($revenueRows)->toHaveCount(1)
        ->and($revenueRows->first()['campaign_id'])->toBe($campaign->getKey())
        ->and($revenueRows->first()['collected_amount'])->toBe(60.0);

    $page = app(ViewCrmReports::class);
    expect($page->reportOptions())->toHaveCount(count(CrmReportType::cases()));
    foreach (CrmReportType::cases() as $type) {
        $page->reportType = $type->value;
        expect($page->rows())->not->toBeEmpty();
        $response = $page->exportCsv();
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        expect($csv)->not->toBe('');
    }
});
