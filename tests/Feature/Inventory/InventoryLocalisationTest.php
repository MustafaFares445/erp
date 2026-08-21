<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory navigation renders translated right-to-left labels in Arabic', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    app()->setLocale('ar');

    $this->actingAs($user)->get(StockLevelResource::getUrl())
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('admin.sections.reporting'))
        ->assertSee(__('admin.resources.stock_levels'));
});
