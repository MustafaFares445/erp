<?php

declare(strict_types=1);

use App\Filament\Pages\PurchaseNeeds;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('covers purchase-needs supplier options for invalid missing and eligible sales orders', function (): void {
    $page = new ReflectionClass(PurchaseNeeds::class)->newInstanceWithoutConstructor();
    $actions = new ReflectionMethod(PurchaseNeeds::class, 'getHeaderActions')->invoke($page);
    $create = $actions[0];

    $schema = new ReflectionProperty($create, 'schema');
    $components = $schema->getValue($create);

    $supplier = collect($components)->first(
        static fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'supplier_id',
    );

    expect($supplier)->toBeInstanceOf(Select::class);

    $optionsProperty = new ReflectionProperty($supplier, 'options');
    $options = $optionsProperty->getValue($supplier);

    expect($options)->toBeInstanceOf(Closure::class);

    $invalid = Mockery::mock(Get::class);
    $invalid->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => $path === 'order_id' ? 'not-numeric' : 'AED',
    );

    expect($options($invalid))->toBe([]);

    $missing = Mockery::mock(Get::class);
    $missing->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => $path === 'order_id' ? 999999999 : 'AED',
    );

    expect($options($missing))->toBe([]);

    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create();
    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '2.000000',
        'fulfilled_base_quantity' => '0.000000',
        'status' => 'open',
    ]);

    $eligible = Supplier::factory()->create([
        'name' => 'Eligible Coverage Supplier',
        'is_active' => true,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $eligible->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'is_active' => true,
        'availability_status' => 'active',
    ]);

    $inactive = Supplier::factory()->create([
        'name' => 'Inactive Coverage Supplier',
        'is_active' => false,
    ]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $inactive->getKey(),
        'product_variant_id' => $variant->getKey(),
        'currency_code' => 'AED',
        'is_active' => true,
        'availability_status' => 'active',
    ]);

    $valid = Mockery::mock(Get::class);
    $valid->shouldReceive('__invoke')->andReturnUsing(
        static fn (string $path): mixed => match ($path) {
            'order_id' => $order->getKey(),
            'currency_code' => 'AED',
            default => null,
        },
    );

    expect($options($valid))
        ->toBe([$eligible->getKey() => 'Eligible Coverage Supplier']);
});
