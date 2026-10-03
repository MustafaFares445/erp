<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory navigation omits forbidden workspaces instead of rendering dead entries', function (): void {
    (new InventoryPermissionSeeder)->run();
    $user = User::factory()->create();
    $user->givePermissionTo(InventoryPermission::StockView->value);

    $this->actingAs($user)->get(StockLevelResource::getUrl())->assertOk();

    $labels = collect(Filament::getPanel('admin')->buildNavigation())
        ->flatMap(fn (NavigationGroup $group): array => $group->getItems())
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();

    expect($labels)->toContain(__('admin.sections.stock'))
        ->not->toContain(__('admin.sections.inbound'), __('admin.sections.outbound'), __('admin.sections.operations'));
});
