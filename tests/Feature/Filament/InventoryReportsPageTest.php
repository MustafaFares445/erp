<?php

declare(strict_types=1);

use App\Filament\Resources\InventoryReports\Pages\ManageInventoryReports;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Release QA 2026-10-10: the report page opened as "Inventory Stocks" and its empty "Active" filter hid every active variant.
it('opens the default catalog report titled as reports and listing active variants', function (): void {
    (new SystemPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    $active = ProductVariant::factory()->create(['is_active' => true]);

    Livewire::actingAs($admin)
        ->test(ManageInventoryReports::class)
        ->assertSeeText('Inventory Reports')
        ->assertDontSeeText('Inventory Stocks')
        ->assertCanSeeTableRecords([$active]);
});
