<?php

declare(strict_types=1);

use App\Filament\AdminModuleRegistry;
use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use App\Filament\Resources\ServiceAppointments\ServiceAppointmentResource;
use App\Filament\Resources\SlaCalendars\SlaCalendarResource;
use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use App\Filament\Resources\SupportEntitlements\SupportEntitlementResource;
use App\Filament\Resources\SupportServiceLevels\SupportServiceLevelResource;
use App\Filament\Resources\SupportTeams\SupportTeamResource;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function supportModuleGroup(): array
{
    foreach (AdminModuleRegistry::groups() as $group) {
        if ($group['key'] === 'support') {
            return $group;
        }
    }

    throw new RuntimeException('Support module missing from the registry.');
}

/** @return list<string> */
function supportSidebarLabels(): array
{
    AdminModuleRegistry::forgetMemoized();

    return collect(AdminModuleRegistry::registeredNavigationItemsFor(supportModuleGroup()))
        ->map(static fn ($item): string => (string) $item->getLabel())
        ->all();
}

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $this->actingAs($manager);
});

it('declares every staged support resource inside the support module', function (): void {
    $members = AdminModuleRegistry::memberClassesOf(supportModuleGroup());

    expect($members)->toContain(
        KnowledgeArticleResource::class,
        KnowledgeArticleCategoryResource::class,
        SlaCalendarResource::class,
        SupportServiceLevelResource::class,
        SupportEntitlementResource::class,
        SupportTeamResource::class,
        SupportAutomationRuleResource::class,
        ServiceAppointmentResource::class,
    );
});

it('keeps optional ITSM capabilities behind one advanced workspace', function (): void {
    config()->set('support.smart_routing_enabled', false);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.knowledge_base_enabled', false);

    expect(supportSidebarLabels())
        ->not->toContain(__('admin.sections.advanced_support'));

    config()->set('support.knowledge_base_enabled', true);

    expect(supportSidebarLabels())
        ->toContain(__('admin.sections.advanced_support'));

    $advanced = collect(supportModuleGroup()['items'])
        ->firstWhere('label', 'admin.sections.advanced_support');

    expect($advanced)->not->toBeNull()
        ->and(collect($advanced['tabs'])->pluck('link'))->toContain(
            KnowledgeArticleResource::class,
            KnowledgeArticleCategoryResource::class,
            SupportTeamResource::class,
            SupportAutomationRuleResource::class,
        )
        ->and(AdminModuleRegistry::resolveItemUrl($advanced))->toBe(KnowledgeArticleResource::getUrl());
});

it('keeps service policies as one compact workspace instead of separate sidebar entries', function (): void {
    config()->set('support.sla_v2_enabled', true);

    $labels = supportSidebarLabels();

    expect($labels)
        ->toContain(__('admin.sections.service_policies'))
        ->not->toContain(__('admin.resources.sla_policies'))
        ->not->toContain(__('admin.sections.sla_setup'));

    $workspace = collect(supportModuleGroup()['items'])
        ->firstWhere('label', 'admin.sections.service_policies');

    expect($workspace)->not->toBeNull()
        ->and(collect($workspace['tabs'])->pluck('link'))->toContain(
            SlaCalendarResource::class,
            SupportServiceLevelResource::class,
            SupportEntitlementResource::class,
        );
});

it('renders the project business scope as a compact nine-destination sidebar', function (): void {
    config()->set('support.field_service_enabled', true);
    config()->set('support.smart_routing_enabled', false);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.knowledge_base_enabled', false);

    expect(supportSidebarLabels())->toBe([
        'Dashboard',
        __('admin.resources.tickets'),
        __('admin.resources.maintenance_requests'),
        __('admin.resources.service_records'),
        __('admin.resources.maintenance_schedules'),
        __('admin.resources.field_service'),
        __('admin.resources.equipment_360'),
        __('admin.resources.support_reports'),
        __('admin.sections.service_policies'),
    ]);
});

it('forbids direct knowledge and sla configuration urls while their flags are off', function (): void {
    config()->set('support.knowledge_base_enabled', false);
    config()->set('support.sla_v2_enabled', false);

    foreach ([
        KnowledgeArticleResource::getUrl(),
        KnowledgeArticleCategoryResource::getUrl(),
        SlaCalendarResource::getUrl(),
        SupportServiceLevelResource::getUrl(),
        SupportEntitlementResource::getUrl(),
    ] as $url) {
        $this->get($url)->assertForbidden();
    }

    config()->set('support.knowledge_base_enabled', true);
    config()->set('support.sla_v2_enabled', true);

    foreach ([
        KnowledgeArticleResource::getUrl(),
        KnowledgeArticleCategoryResource::getUrl(),
        SlaCalendarResource::getUrl(),
        SupportServiceLevelResource::getUrl(),
        SupportEntitlementResource::getUrl(),
    ] as $url) {
        $this->get($url)->assertSuccessful();
    }
});

it('renders the support sidebar section headings in both languages', function (): void {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach (supportModuleGroup()['sections'] as $section) {
            expect(__($section['label']))->not->toBe($section['label']);
        }
    }
});
