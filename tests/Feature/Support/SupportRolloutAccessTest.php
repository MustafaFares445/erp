<?php

declare(strict_types=1);

use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
use App\Filament\Resources\ServiceAppointments\ServiceAppointmentResource;
use App\Filament\Resources\SupportAutomationRules\SupportAutomationRuleResource;
use App\Filament\Resources\SupportQueues\SupportQueueResource;
use App\Filament\Resources\SupportRoutingRules\SupportRoutingRuleResource;
use App\Filament\Resources\SupportSkills\SupportSkillResource;
use App\Filament\Resources\SupportTeams\SupportTeamResource;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $this->actingAs($manager);
});

it('blocks staged support resources from both navigation and direct list access while disabled', function (): void {
    config()->set('support.smart_routing_enabled', false);
    config()->set('support.support_automation_enabled', false);
    config()->set('support.field_service_enabled', false);
    config()->set('support.knowledge_base_enabled', false);

    foreach ([
        SupportTeamResource::class,
        SupportSkillResource::class,
        SupportQueueResource::class,
        SupportRoutingRuleResource::class,
        SupportAutomationRuleResource::class,
        ServiceAppointmentResource::class,
        KnowledgeArticleResource::class,
        KnowledgeArticleCategoryResource::class,
    ] as $resource) {
        expect($resource::shouldRegisterNavigation())->toBeFalse($resource)
            ->and($resource::canViewAny())->toBeFalse($resource);
    }
});

it('restores policy-controlled access when each rollout switch is enabled', function (): void {
    config()->set('support.smart_routing_enabled', true);
    config()->set('support.support_automation_enabled', true);
    config()->set('support.field_service_enabled', true);
    config()->set('support.knowledge_base_enabled', true);

    foreach ([
        SupportTeamResource::class,
        SupportSkillResource::class,
        SupportQueueResource::class,
        SupportRoutingRuleResource::class,
        SupportAutomationRuleResource::class,
        ServiceAppointmentResource::class,
        KnowledgeArticleResource::class,
        KnowledgeArticleCategoryResource::class,
    ] as $resource) {
        expect($resource::canViewAny())->toBeTrue($resource);
    }

    expect(ServiceAppointmentResource::canCreate())->toBeFalse();
});
