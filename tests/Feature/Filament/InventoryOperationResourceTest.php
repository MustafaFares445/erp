<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Filament\Resources\InventoryOperations\InventoryOperationResource;
use App\Filament\Resources\InventoryOperations\Schemas\OperationLinesRepeater;
use App\Models\InventoryOperation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('renders the inventory operations index with an enum stage', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->receipt()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('index'))
        ->assertOk()
        ->assertSee($operation->stage->label());
});

it('renders an inventory operation view with an enum stage', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-operation-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::ReceiptView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    $operation = InventoryOperation::factory()->receipt()->create();

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('view', ['record' => $operation]))
        ->assertOk()
        ->assertSee($operation->stage->label());
});

it('filters operation line variants by the selected active product', function (): void {
    $product = Product::factory()->create();
    $matchingVariant = ProductVariant::factory()->for($product)->create(['sku' => 'MATCHING-SKU']);
    ProductVariant::factory()->for($product)->create(['sku' => 'INACTIVE-SKU', 'is_active' => false]);
    $otherProductVariant = ProductVariant::factory()->create(['sku' => 'OTHER-SKU']);

    $variantOptions = new ReflectionMethod(
        OperationLinesRepeater::class,
        'variantOptions',
    );
    expect($variantOptions->invoke(null, $product->getKey()))
        ->toBe([$matchingVariant->getKey() => 'MATCHING-SKU'])
        ->not->toContain($otherProductVariant->getKey());
});

it('renders the source document type as a readable label on deliveries', function (): void {
    $role = Role::firstOrCreate(['name' => 'inventory-delivery-viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(InventoryPermission::DeliveryView->value);

    $user = User::factory()->create();
    $user->assignRole($role);

    InventoryOperation::factory()->delivery()->create([
        'source_document_type' => 'sales_order',
    ]);

    $this->actingAs($user)
        ->get(InventoryOperationResource::getUrl('deliveries'))
        ->assertOk()
        ->assertSee('Sales Order')
        ->assertDontSee('sales_order');
});
