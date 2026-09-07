<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Pages\Settings;
use App\Filament\Resources\InventorySettings\InventorySettingResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/**
 * WP-3.7 (GAP-UI-07, MD-08) — the settings hub is navigation over the
 * settings pages that already exist; each card is filtered by the same
 * permission its own resource enforces, so hiding a card is a convenience,
 * never the authorisation.
 */
it('renders the settings hub with a card per accessible settings screen', function (): void {
    $actor = User::factory()->admin()->create();

    Livewire::actingAs($actor)
        ->test(Settings::class)
        ->assertSuccessful()
        ->assertSee('Document Templates');
});

it('filters out a card the actor is not authorized to open', function (): void {
    $actor = User::factory()->create();

    $page = Livewire::actingAs($actor)->test(Settings::class);

    $urls = collect($page->instance()->cards())->pluck('url');

    expect($urls)->not->toContain(InventorySettingResource::getUrl());
});

it('shows the inventory settings card once the actor has the permission', function (): void {
    $actor = User::factory()->create();
    $actor->givePermissionTo(Permission::findOrCreate(InventoryPermission::PricingView->value, 'web'));

    $page = Livewire::actingAs($actor)->test(Settings::class);

    $urls = collect($page->instance()->cards())->pluck('url');

    expect($urls)->toContain(InventorySettingResource::getUrl());
});
