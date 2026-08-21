<?php

declare(strict_types=1);

use App\Filament\Resources\PricingTiers\PricingTierResource;
use App\Models\User;
use Database\Seeders\CrmPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('uses English pricing tier terminology without a product subscriptions surface', function (): void {
    (new CrmPermissionSeeder)->run();
    $reviewer = User::factory()->admin()->create();
    $reviewer->assignRole('Reviewer');

    app()->setLocale('en');

    $this->actingAs($reviewer)
        ->get(PricingTierResource::getUrl())
        ->assertOk()
        ->assertSee('Pricing Tiers')
        ->assertDontSee('Product Subscriptions');

    $this->get('/admin/product-subscriptions')->assertNotFound();
});
