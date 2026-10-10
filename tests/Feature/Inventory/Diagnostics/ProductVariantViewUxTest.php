<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\ProductVariants\Pages\ViewProductVariant;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the real Product Variant View with explanations rather than redirecting to the management list', function (): void {
    (new InventoryPermissionSeeder)->run();

    $user = User::factory()->admin()->create();
    $user->givePermissionTo(array_map(
        static fn (InventoryPermission $permission): string => $permission->value,
        InventoryPermission::cases(),
    ));

    $variant = ProductVariant::factory()->create();

    Livewire::actingAs($user)
        ->test(ViewProductVariant::class, ['record' => $variant->getKey()])
        ->assertOk()
        ->assertNoRedirect()
        ->assertSee($variant->sku)
        ->assertSee(__('inventory_view_explanations.variant_identity_title'));
});
