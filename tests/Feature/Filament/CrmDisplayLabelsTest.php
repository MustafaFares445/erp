<?php

declare(strict_types=1);

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use App\Enums\CrmPermission;
use App\Enums\CrmReportType;
use App\Enums\CustomerType;
use App\Enums\InteractionDirection;
use App\Enums\InteractionType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\NotificationChannel;
use App\Enums\OpportunityStage;
use App\Enums\SalesOpportunityStatus;
use App\Filament\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\CrmReports\Pages\ViewCrmReports;
use App\Filament\Resources\CustomerQuotationRequests\Pages\ListCustomerQuotationRequests;
use App\Filament\Resources\CustomerReturnRequests\Pages\ListCustomerReturnRequests;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Interactions\InteractionResource;
use App\Filament\Resources\Interactions\Pages\ListInteractions;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\LeadInteractionsRelationManager;
use App\Filament\Resources\PriceFloorOverrides\Pages\ViewPriceFloorOverride;
use App\Filament\Resources\SalesOpportunities\Pages\ListSalesOpportunities;
use App\Filament\Resources\SalesOpportunities\Pages\ViewSalesOpportunity;
use App\Models\Campaign;
use App\Models\CustomerProfile;
use App\Models\Interaction;
use App\Models\Lead;
use App\Models\NotificationTemplate;
use App\Models\PriceFloorOverride;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Services\Notifications\NotificationTemplateCatalog;
use Database\Seeders\CrmPermissionSeeder;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function crmDisplayLead(string $language = 'en'): Lead
{
    $lead = new Lead;
    $lead->forceFill([
        'lead_number' => 'LEAD-DISPLAY-001',
        'status' => LeadStatus::New,
        'source' => LeadSource::Website,
        'first_name' => 'Display',
        'last_name' => 'Lead',
        'email' => 'display-lead@example.test',
        'preferred_language' => $language,
        'created_by' => User::factory()->create()->getKey(),
    ])->save();

    return $lead;
}

it('shows the lead preferred language by name instead of its code', function (string $locale, string $language, string $expected): void {
    app()->setLocale($locale);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ViewLead::class, ['record' => crmDisplayLead($language)->getKey()])
        ->assertSeeText($expected)
        ->assertDontSeeHtml('>'.$language.'<');
})->with([
    'english in english' => ['en', 'en', 'English'],
    'arabic in english' => ['en', 'ar', 'Arabic'],
    'arabic in arabic' => ['ar', 'ar', 'العربية'],
]);

it('shows the party type of an interaction as a translated label', function (): void {
    $customer = CustomerProfile::factory()->create();
    $lead = crmDisplayLead();
    $interactions = [$customer, $lead];

    foreach ($interactions as $subject) {
        Interaction::query()->create([
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'type' => InteractionType::Call,
            'direction' => InteractionDirection::Outbound,
            'occurred_at' => now(),
            'summary' => 'Party label check',
            'employee_id' => User::factory()->create()->getKey(),
        ]);
    }

    $admin = User::factory()->admin()->create();
    $records = Interaction::query()->orderBy('id')->get();

    Livewire::actingAs($admin)
        ->test(ListInteractions::class)
        ->assertTableColumnFormattedStateSet('subject_type', 'Customer', record: $records[0])
        ->assertTableColumnFormattedStateSet('subject_type', 'Lead', record: $records[1]);

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test(ListInteractions::class)
        ->assertTableColumnFormattedStateSet('subject_type', 'العميل', record: $records[0])
        ->assertTableColumnFormattedStateSet('subject_type', 'عميل محتمل', record: $records[1]);
});

it('falls back to the class name for an unknown party type', function (): void {
    expect(InteractionResource::partyTypeLabel('App\Models\FieldTechnician'))->toBe('FieldTechnician');
});

it('translates the lead custom field values relation manager in Arabic', function (): void {
    $this->seed(CrmPermissionSeeder::class);
    app()->setLocale('ar');
    $lead = crmDisplayLead();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(CustomFieldsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
        ->assertSeeText('قيم الحقول المخصصة')
        ->assertSeeText('الحقل المخصص')
        ->assertSeeText('لا توجد قيم للحقول المخصصة')
        ->assertDontSeeText('Custom Field Values')
        ->assertDontSeeText('No custom field values');
});

it('renders the sales opportunity AI review sections in Arabic', function (): void {
    app()->setLocale('ar');
    $opportunity = SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test(ViewSalesOpportunity::class, ['record' => $opportunity->getKey()]);

    foreach ([
        'مراجعة فرصة الذكاء الاصطناعي',
        'مخرجات الذكاء الاصطناعي هي دليل قابل للمراجعة فقط',
        'الزيارة المصدر',
        'المنتج المكتشف',
        'المتغير المكتشف',
        'الملاحظة الصوتية',
        'تاريخ الاكتشاف',
        'مقتطف من النص المفرغ',
        'لا يوجد مقتطف محفوظ',
        'ملخص الذكاء الاصطناعي',
        'قرار المراجعة',
        'دليل المصدر',
        'دليل المصدر المحفوظ',
    ] as $arabic) {
        $component->assertSeeText($arabic);
    }

    foreach (['AI opportunity review', 'Review decision', 'Origin evidence', 'Preserved origin evidence', 'Source visit', 'Detected product', 'Detected variant', 'Detected at', 'Voice note', 'Transcript excerpt', 'AI summary', 'No excerpt retained'] as $english) {
        $component->assertDontSeeText($english);
    }
});

it('groups the secondary request row actions into an overflow menu', function (string $page): void {
    $table = Livewire::actingAs(User::factory()->admin()->create())
        ->test($page)
        ->instance()
        ->getTable();

    $groups = array_filter($table->getRecordActions(), static fn (mixed $action): bool => $action instanceof ActionGroup);

    expect($groups)->toHaveCount(1)
        ->and(array_map(static fn (mixed $action): string => $action::class, $table->getRecordActions()))->toContain(ActionGroup::class);
})->with([
    'return requests' => [ListCustomerReturnRequests::class],
    'quotation requests' => [ListCustomerQuotationRequests::class],
]);

it('colours CRM opportunity and campaign badges by their meaning', function (): void {
    // Release QA 2026-10-10: rejected/closed-lost opportunities and failed/cancelled campaigns rendered in the same blue as healthy states.
    $admin = User::factory()->admin()->create();
    $rejected = SalesOpportunity::factory()->create([
        'status' => SalesOpportunityStatus::Rejected,
        'stage' => OpportunityStage::ClosedLost,
    ]);
    $pending = SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);
    $colorOf = static fn (string $expected): Closure => static fn (TextColumn $column): bool => $column->getColor($column->getState()) === $expected;

    Livewire::actingAs($admin)
        ->test(ListSalesOpportunities::class)
        ->assertTableColumnExists('status', $colorOf('danger'), $rejected)
        ->assertTableColumnExists('stage', $colorOf('danger'), $rejected)
        ->assertTableColumnExists('status', $colorOf('info'), $pending);

    $campaigns = collect([CampaignStatus::Failed, CampaignStatus::Cancelled, CampaignStatus::Completed])
        ->mapWithKeys(static fn (CampaignStatus $status): array => [$status->value => Campaign::query()->forceCreate([
            'campaign_number' => 'CMP-COLOR-'.$status->value,
            'name' => 'Colour '.$status->value,
            'channel' => CampaignChannel::Email,
            'status' => $status,
            'created_by' => $admin->getKey(),
        ])]);

    Livewire::actingAs($admin)
        ->test(ListCampaigns::class)
        ->assertTableColumnExists('status', $colorOf('danger'), $campaigns['failed'])
        ->assertTableColumnExists('status', $colorOf('danger'), $campaigns['cancelled'])
        ->assertTableColumnExists('status', $colorOf('success'), $campaigns['completed']);
});

it('passes the opportunity AI review row actions through the translator', function (): void {
    // Release QA 2026-10-10: "Approve AI review" stayed English in Arabic because the label was never translated.
    app()->setLocale('ar');
    app('translator')->addLines(['*.Approve AI review' => 'اعتماد مراجعة الذكاء الاصطناعي'], 'ar');
    $draft = SalesOpportunity::factory()->create(['status' => SalesOpportunityStatus::Draft]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListSalesOpportunities::class)
        ->assertTableActionHasLabel('approve', 'اعتماد مراجعة الذكاء الاصطناعي', $draft);
});

it('explains that a human-created opportunity needs no AI review', function (): void {
    // Release QA 2026-10-10: a manual opportunity showed "Accepted" next to "Reviewed by: Not reviewed".
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test(ViewSalesOpportunity::class, ['record' => SalesOpportunity::factory()->manual()->create()->getKey()])
        ->assertSeeText('Not required (human-created opportunity)')
        ->assertDontSeeText('Not reviewed');

    Livewire::actingAs($admin)
        ->test(ViewSalesOpportunity::class, ['record' => SalesOpportunity::factory()->create()->getKey()])
        ->assertSeeText('Not reviewed')
        ->assertDontSeeText('Not required (human-created opportunity)');
});

it('shows the interaction party by name instead of its database id', function (): void {
    // Release QA 2026-10-10: the interactions index showed a bare "Party ID" column.
    $customer = CustomerProfile::factory()->create(['company_name' => 'Harmony Dental Clinic']);
    $lead = crmDisplayLead();
    $records = collect([$customer, $lead])->map(static fn (CustomerProfile|Lead $subject): Interaction => Interaction::query()->create([
        'subject_type' => $subject::class,
        'subject_id' => $subject->getKey(),
        'type' => InteractionType::Call,
        'direction' => InteractionDirection::Outbound,
        'occurred_at' => now(),
        'summary' => 'Party name check',
        'employee_id' => User::factory()->create()->getKey(),
    ]));

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ListInteractions::class)
        ->assertTableColumnDoesNotExist('subject_id')
        ->assertTableColumnExists('party', static fn (TextColumn $column): bool => $column->getLabel() === 'Party')
        ->assertTableColumnStateSet('party', 'Harmony Dental Clinic', record: $records[0])
        ->assertTableColumnStateSet('party', 'Display Lead', record: $records[1]);
});

it('shows a campaign content template by its display name instead of its raw key', function (): void {
    // Release QA 2026-10-10: the campaign view printed the template key "crm.campaign.demo.newsletter".
    $admin = User::factory()->admin()->create();
    $template = NotificationTemplate::query()->create([
        'key' => 'crm.campaign.demo.newsletter',
        'locale' => 'en',
        'channel' => NotificationChannel::Mail,
        'subject' => 'Autumn news',
        'body' => 'Hello',
        'variables' => [],
        'is_active' => true,
    ]);
    $campaign = Campaign::query()->forceCreate([
        'campaign_number' => 'CMP-TEMPLATE-001',
        'name' => 'Template name check',
        'channel' => CampaignChannel::Email,
        'status' => CampaignStatus::Draft,
        'content_template_id' => $template->getKey(),
        'created_by' => $admin->getKey(),
    ]);

    Livewire::actingAs($admin)
        ->test(ViewCampaign::class, ['record' => $campaign->getKey()])
        ->assertSeeText(app(NotificationTemplateCatalog::class)->name('crm.campaign.demo.newsletter'))
        ->assertDontSeeText('crm.campaign.demo.newsletter');
});

it('labels CRM report lead sources and stages with their translated enum labels', function (): void {
    // Release QA 2026-10-10: the CRM report Source column printed raw enum values such as "cold_call".
    crmDisplayLead();
    Lead::query()->update(['source' => LeadSource::ColdCall->value]);
    $this->seed(CrmPermissionSeeder::class);
    $this->actingAs(User::factory()->create()->givePermissionTo(CrmPermission::FunnelReport->value));
    $page = app(ViewCrmReports::class);

    $page->reportType = CrmReportType::LeadsBySource->value;
    expect($page->rows()->first()[__('Source')])->toBe(LeadSource::ColdCall->label())
        ->and(LeadSource::ColdCall->label())->not->toBe('cold_call');

    $page->reportType = CrmReportType::StageConversion->value;
    expect($page->rows()->first()[__('Stage')])->toBe(LeadStatus::New->label());
});

it('labels the customer active toggle and translates the customer type options', function (): void {
    // Release QA 2026-10-10: the customer form showed the generated label "Is active" and English customer types in Arabic.
    Livewire::actingAs(User::factory()->admin()->create())
        ->test(CreateCustomer::class)
        ->assertFormFieldExists('is_active', static fn (Toggle $field): bool => $field->getLabel() === 'Active');

    app()->setLocale('ar');

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(CreateCustomer::class)
        ->assertFormFieldExists('customer_type', static fn (Select $field): bool => $field->getOptions()[CustomerType::Other->value] === 'أخرى');
});

it('renders empty customer commercial facts as a dash and the country by name', function (): void {
    // Release QA 2026-10-10: empty commercial values showed a literal "?" and the country showed the raw code "AE".
    $customer = CustomerProfile::factory()->create(['country' => 'AE', 'address' => 'Clinic Street 1', 'customer_group_id' => null]);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ViewCustomer::class, ['record' => $customer->getKey()])
        ->assertSchemaComponentExists('customerGroup.name', 'infolist', static fn (TextEntry $entry): bool => $entry->getPlaceholder() === '—')
        ->assertSeeText('United Arab Emirates')
        ->assertSeeText('Approved when the account was created')
        ->assertDontSeeText('Not reviewed yet');
});

it('opens the lead view on its interactions instead of the custom field values', function (): void {
    // Release QA 2026-10-10: the empty "Custom Field Values" tab was the first, dominating lead relation tab.
    expect(LeadResource::getRelations()[0])->toBe(LeadInteractionsRelationManager::class)
        ->and(LeadResource::getRelations())->toContain(CustomFieldsRelationManager::class);
});

it('isolates the direction of a free-text price floor override reason', function (): void {
    // Release QA 2026-10-10: in Arabic the English reason's trailing period jumped to the start of the line.
    $override = PriceFloorOverride::factory()->create(['reason' => 'Strategic contract approval.']);

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(ViewPriceFloorOverride::class, ['record' => $override->getKey()])
        ->assertSeeHtml('<bdi>Strategic contract approval.</bdi>');
});
