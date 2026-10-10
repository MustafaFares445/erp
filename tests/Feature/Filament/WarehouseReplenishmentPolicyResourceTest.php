<?php

declare(strict_types=1);

use App\Filament\Resources\WarehouseReplenishmentPolicies\Pages\ManageWarehouseReplenishmentPolicies;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SystemPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Release QA 2026-10-10: the create button read "New Replenishment Policies" (plural model label).
it('names one replenishment policy in the create action', function (string $locale, string $createLabel, string $pluralCreateLabel): void {
    (new SystemPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();
    $admin = User::factory()->admin()->create();
    $admin->assignRole('System Admin');
    app()->setLocale($locale);

    Livewire::actingAs($admin)
        ->test(ManageWarehouseReplenishmentPolicies::class)
        ->assertSeeText($createLabel)
        ->assertDontSeeText($pluralCreateLabel);
})->with([
    'English' => ['en', 'New Replenishment Policy', 'New Replenishment Policies'],
    'Arabic' => ['ar', 'سياسة إعادة التزويد', 'إضافة سياسات إعادة التزويد'],
]);
