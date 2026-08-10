<?php

declare(strict_types=1);

use App\Filament\Resources\OpportunityDrafts\OpportunityDraftResource;
use App\Models\SalesOpportunityDraft;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('renders the opportunity draft list and view pages without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $draft = SalesOpportunityDraft::factory()->create();

    $this->actingAs($admin)->get(OpportunityDraftResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(OpportunityDraftResource::getUrl('view', ['record' => $draft]))->assertOk();
});
