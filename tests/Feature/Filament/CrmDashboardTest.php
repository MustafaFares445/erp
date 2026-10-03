<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CampaignSendStatus;
use App\Enums\CampaignStatus;
use App\Enums\CrmPermission;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Pages\CrmDashboard;
use App\Filament\Widgets\CrmCampaignPerformance;
use App\Filament\Widgets\CrmCustomerGrowthTrend;
use App\Filament\Widgets\CrmDormantLeads;
use App\Filament\Widgets\CrmLeadFunnel;
use App\Filament\Widgets\CrmStatistics;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomerProfile;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new CrmPermissionSeeder)->run();
});

function crmViewer(CrmPermission ...$permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(array_map(static fn (CrmPermission $permission): string => $permission->value, $permissions));

    return $user;
}

/**
 * @param  array<string, mixed>  $filters
 * @return list<Stat>
 */
function crmStats(array $filters = []): array
{
    $widget = app(CrmStatistics::class);
    $widget->pageFilters = $filters;

    /** @var list<Stat> */
    return new ReflectionMethod($widget, 'getStats')->invoke($widget);
}

function crmLead(array $attributes = []): Lead
{
    $lead = new Lead;
    $lead->forceFill([
        'lead_number' => 'LEAD-'.fake()->unique()->numerify('#####'),
        'status' => LeadStatus::New,
        'source' => LeadSource::Website,
        'first_name' => fake()->firstName(),
        'created_by' => User::factory()->create()->getKey(),
        ...$attributes,
    ])->save();

    return $lead;
}

it('gates the CRM dashboard behind the customer view permission', function (): void {
    $this->actingAs(User::factory()->create());

    expect(CrmDashboard::canAccess())->toBeFalse();

    $this->actingAs(crmViewer(CrmPermission::CustomerView));

    expect(CrmDashboard::canAccess())->toBeTrue();
});

it('allows CRM dashboard access with campaign view permission only', function (): void {
    $this->actingAs(crmViewer(CrmPermission::CampaignView));

    expect(CrmDashboard::canAccess())->toBeTrue()
        ->and(CrmStatistics::canView())->toBeFalse()
        ->and(CrmCampaignPerformance::canView())->toBeTrue()
        ->and(CrmDormantLeads::canView())->toBeFalse();
});

it('shows only the KPI cards the user may see', function (): void {
    $this->actingAs(crmViewer(CrmPermission::CustomerView));

    expect(crmStats())->toHaveCount(2);

    $this->actingAs(crmViewer(CrmPermission::LeadView));

    expect(crmStats())->toHaveCount(2);

    $this->actingAs(crmViewer(CrmPermission::CustomerView, CrmPermission::LeadView));

    expect(crmStats())->toHaveCount(4);
});

it('reports new and active customers against the previous period', function (): void {
    $this->actingAs(crmViewer(CrmPermission::CustomerView));

    CustomerProfile::factory()->count(2)->create(['created_at' => now(), 'is_active' => true]);
    CustomerProfile::factory()->create(['created_at' => now()->subDays(40), 'is_active' => false]);

    [$new, $active] = crmStats();

    expect($new->getValue())->toBe('2')
        ->and((string) $new->getDescription())->toBe('100.0% increase')
        ->and(array_sum($new->getChart() ?? []))->toBe(2)
        ->and($active->getValue())->toBe('2')
        ->and((string) $active->getDescription())->toBe('3 customers in total');
});

it('reports new leads and their conversion for the selected lead source', function (): void {
    $this->actingAs(crmViewer(CrmPermission::LeadView));

    crmLead(['status' => LeadStatus::Converted, 'source' => LeadSource::Referral]);
    crmLead(['status' => LeadStatus::New, 'source' => LeadSource::Referral]);
    crmLead(['status' => LeadStatus::New, 'source' => LeadSource::Website]);

    [$leads, $conversion] = crmStats();

    expect($leads->getValue())->toBe('3')
        ->and($conversion->getValue())->toBe('33.3%');

    [$leads, $conversion] = crmStats(['leadSource' => LeadSource::Referral->value]);

    expect($leads->getValue())->toBe('2')
        ->and($conversion->getValue())->toBe('50.0%');

    [, $conversion] = crmStats(['leadSource' => LeadSource::Partner->value]);

    expect($conversion->getValue())->toBe('—');
});

it('charts new customers for the selected and previous period', function (): void {
    CustomerProfile::factory()->create(['created_at' => now()]);
    CustomerProfile::factory()->create(['created_at' => now()->subDays(35)]);

    $widget = app(CrmCustomerGrowthTrend::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('line')
        ->and($data['labels'])->toHaveCount(30)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(1)
        ->and(array_sum($data['datasets'][1]['data']))->toBe(1);
});

it('splits new leads by status in a doughnut', function (): void {
    crmLead(['status' => LeadStatus::Converted]);
    crmLead(['status' => LeadStatus::New]);
    crmLead(['status' => LeadStatus::New, 'source' => LeadSource::Partner]);

    $widget = app(CrmLeadFunnel::class);
    $data = new ReflectionMethod($widget, 'getData')->invoke($widget);

    expect(new ReflectionMethod($widget, 'getType')->invoke($widget))->toBe('doughnut')
        ->and($data['labels'])->toBe(array_map(static fn (LeadStatus $status): string => $status->label(), LeadStatus::cases()))
        ->and($data['datasets'][0]['data'])->toBe([2, 0, 0, 1, 0]);

    $widget->pageFilters = ['leadSource' => LeadSource::Partner->value];

    expect(new ReflectionMethod($widget, 'getData')->invoke($widget)['datasets'][0]['data'])->toBe([1, 0, 0, 0, 0]);
});

it('lists dormant leads and campaign performance', function (): void {
    $this->actingAs(crmViewer(CrmPermission::LeadView, CrmPermission::CampaignView));

    $dormant = crmLead(['first_name' => 'Silent', 'last_interaction_at' => now()->subDays(30)]);
    $active = crmLead(['first_name' => 'Chatty', 'last_interaction_at' => now()]);

    Livewire::test(CrmDormantLeads::class)
        ->assertCanSeeTableRecords([$dormant])
        ->assertCanNotSeeTableRecords([$active]);

    Livewire::test(CrmDormantLeads::class, ['pageFilters' => ['leadSource' => LeadSource::Partner->value]])
        ->assertCanNotSeeTableRecords([$dormant]);

    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-DASHBOARD',
        'name' => 'Spring Whitening',
        'status' => CampaignStatus::Draft,
        'channel' => CampaignChannel::Email,
        'created_by' => User::factory()->create()->getKey(),
        'segment_criteria' => [],
    ])->save();

    foreach ([CampaignSendStatus::Sent, CampaignSendStatus::Sent, CampaignSendStatus::Failed] as $index => $status) {
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->getKey(),
            'recipient_type' => 'customer',
            'recipient_id' => $index + 1,
            'send_status' => $status->value,
        ]);
    }

    Livewire::test(CrmCampaignPerformance::class)
        ->assertCanSeeTableRecords([$campaign])
        ->assertSee('Spring Whitening')
        ->assertTableColumnStateSet('sent_count', 2, $campaign)
        ->assertTableColumnStateSet('failed_count', 1, $campaign);
});

it('lays out the CRM dashboard in aligned pairs with a lead source filter', function (): void {
    $this->actingAs(crmViewer(CrmPermission::LeadView));

    expect(CrmDashboard::getNavigationLabel())->toBe(__('admin.dashboard'))
        ->and(new ReflectionMethod(CrmDashboard::class, 'getDashboardWidgets')->invoke(new CrmDashboard))->toBe([
            CrmStatistics::class,
            [CrmCustomerGrowthTrend::class, CrmLeadFunnel::class],
            [CrmDormantLeads::class, CrmCampaignPerformance::class],
        ]);

    Livewire::test(CrmDashboard::class)
        ->assertSuccessful()
        ->assertSee('Lead source')
        ->set('filters.leadSource', LeadSource::Referral->value)
        ->assertSet('filters.leadSource', LeadSource::Referral->value);
});
