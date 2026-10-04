<?php

declare(strict_types=1);

use App\Enums\SupportAutomationEvent;
use App\Filament\Resources\SupportAutomationRules\Pages\ListSupportAutomationRules;
use App\Models\SupportAutomationRule;
use App\Models\User;
use Database\Seeders\SupportPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders automation rules for support managers', function (): void {
    config()->set('support.support_automation_enabled', true);

    (new SupportPermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole('Support Manager');

    $rule = SupportAutomationRule::query()->create([
        'name' => 'Escalate SLA risk',
        'event_key' => SupportAutomationEvent::SlaAtRisk,
        'precedence' => 10,
        'is_active' => true,
        'conditions' => [],
        'actions' => [['type' => 'auto_assign']],
    ]);

    Livewire::actingAs($manager)
        ->test(ListSupportAutomationRules::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$rule]);
});
