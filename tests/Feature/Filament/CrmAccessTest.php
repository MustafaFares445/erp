<?php

declare(strict_types=1);

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
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

    Livewire::actingAs($reviewer)
        ->test(ListCampaigns::class)
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
