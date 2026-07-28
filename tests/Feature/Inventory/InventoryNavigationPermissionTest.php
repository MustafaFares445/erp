<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory navigation omits forbidden sections instead of rendering empty menus', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $this->actingAs($user)->get(StockLevelResource::getUrl())->assertOk();

    $labels = collect(Filament::getPanel('admin')->buildNavigation())
        ->filter(fn (NavigationGroup $group): bool => filled($group->getLabel()))
        ->map(fn (NavigationGroup $group): string => $group->getLabel())
        ->values()
        ->all();

    expect($labels)->toContain(__('admin.sections.products'), __('admin.sections.reporting'))
        ->not->toContain(__('admin.sections.operations'), __('admin.sections.configurations'));
});
