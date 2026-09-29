<?php

declare(strict_types=1);

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows reviewer CRM visibility while withholding mutation access in Filament', function (): void {
    $this->seed(CrmPermissionSeeder::class);

    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    Livewire::actingAs($reviewer)
        ->test(ListLeads::class)
        ->assertSuccessful();

    $terminalLead = new Lead;
    $terminalLead->forceFill([
        'lead_number' => 'LEAD-CRM-VIEW-001',
        'status' => LeadStatus::Disqualified,
        'source' => LeadSource::Website,
        'first_name' => 'Terminal',
        'last_name' => 'Lead',
        'email' => 'terminal-lead-view@example.test',
        'created_by' => $reviewer->getKey(),
    ])->save();

    Livewire::actingAs($reviewer)
        ->test(ViewLead::class, ['record' => $terminalLead->getKey()])
        ->assertSuccessful();

    Livewire::actingAs($reviewer)
        ->test(ListCampaigns::class)
        ->assertSuccessful();

    $campaign = new Campaign;
    $campaign->forceFill([
        'campaign_number' => 'CMP-CRM-VIEW-001',
        'name' => 'CRM view coverage',
        'channel' => 'email',
        'status' => 'draft',
        'segment_criteria' => [],
        'created_by' => $reviewer->getKey(),
    ])->save();

    Livewire::actingAs($reviewer)
        ->test(ViewCampaign::class, ['record' => $campaign->getKey()])
        ->assertSuccessful();

    $this->actingAs($reviewer);

    expect(LeadResource::canViewAny())->toBeTrue()
        ->and(LeadResource::canCreate())->toBeFalse()
        ->and(CampaignResource::canViewAny())->toBeTrue()
        ->and(CampaignResource::canCreate())->toBeFalse();
});

it('allows CRM managers to create leads and campaigns in Filament', function (): void {
    $this->seed(CrmPermissionSeeder::class);

    $manager = User::factory()->admin()->create();
    $manager->assignRole('CRM Manager');
    $this->actingAs($manager);

    expect(LeadResource::canCreate())->toBeTrue()
        ->and(CampaignResource::canCreate())->toBeTrue();
});
