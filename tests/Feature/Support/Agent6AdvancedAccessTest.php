<?php

declare(strict_types=1);

use App\Filament\Resources\KnowledgeArticleCategories\KnowledgeArticleCategoryResource;
use App\Filament\Resources\KnowledgeArticles\KnowledgeArticleResource;
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
});

it('requires a rollout flag and a genuine permission for every advanced Support resource', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $resources = [
        [SupportTeamResource::class, 'smart_routing_enabled'],
        [SupportSkillResource::class, 'smart_routing_enabled'],
        [SupportQueueResource::class, 'smart_routing_enabled'],
        [SupportRoutingRuleResource::class, 'smart_routing_enabled'],
        [SupportAutomationRuleResource::class, 'support_automation_enabled'],
        [KnowledgeArticleResource::class, 'knowledge_base_enabled'],
        [KnowledgeArticleCategoryResource::class, 'knowledge_base_enabled'],
    ];
    $this->actingAs($admin);
    foreach ($resources as [$resource, $flag]) {
        config()->set('support.'.$flag, false);
        expect($resource::canViewAny())->toBeFalse()
            ->and($resource::canCreate())->toBeFalse();

        config()->set('support.'.$flag, true);
        expect($resource::canViewAny())->toBeTrue()
            ->and($resource::canCreate())->toBeTrue();
    }
});

it('lets a Reviewer read enabled advanced workspaces without creating configuration records', function (): void {
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    config()->set('support.smart_routing_enabled', true);
    config()->set('support.support_automation_enabled', true);
    config()->set('support.knowledge_base_enabled', true);
    $this->actingAs($reviewer);

    foreach ([
        SupportTeamResource::class,
        SupportQueueResource::class,
        SupportRoutingRuleResource::class,
        SupportAutomationRuleResource::class,
        KnowledgeArticleResource::class,
        KnowledgeArticleCategoryResource::class,
    ] as $resource) {
        expect($resource::canViewAny())->toBeTrue()
            ->and($resource::canCreate())->toBeFalse();
    }
});
