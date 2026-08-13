<?php

declare(strict_types=1);

use App\Filament\Resources\SalesOpportunities\SalesOpportunityResource;
use App\Models\SalesOpportunity;
use App\Models\User;
use Database\Seeders\EmployeePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new EmployeePermissionSeeder)->run();
});

it('renders the sales opportunity list and view pages without error', function (): void {
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');

    $draft = SalesOpportunity::factory()->create();

    $this->actingAs($admin)->get(SalesOpportunityResource::getUrl('index'))->assertOk();
    $this->actingAs($admin)->get(SalesOpportunityResource::getUrl('view', ['record' => $draft]))->assertOk();
});
