<?php

declare(strict_types=1);

use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Models\Currency;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});

it('halts purchase order creation without an authenticated purchasing actor', function (): void {
    $page = new ReflectionClass(CreatePurchaseOrder::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreatePurchaseOrder::class, 'handleRecordCreation');

    auth()->logout();

    expect(fn (): mixed => $create->invoke($page, [
        'supplier_id' => 1,
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
        'lines' => [],
    ]))->toThrow(Halt::class);
});

it('normalizes malformed purchase order line payloads into the service validation path', function (): void {
    Currency::query()->where('code', 'AED')->update(['is_active' => true]);

    $actor = User::factory()->admin()->create();
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $this->actingAs($actor);

    $page = new ReflectionClass(CreatePurchaseOrder::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreatePurchaseOrder::class, 'handleRecordCreation');

    expect(fn (): mixed => $create->invoke($page, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
        'lines' => 'not-an-array',
    ]))->toThrow(Halt::class);

    expect(fn (): mixed => $create->invoke($page, [
        'supplier_id' => $supplier->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
        'lines' => ['skip-me'],
    ]))->toThrow(Halt::class);
});

it('creates a purchase order through the page service adapter with normalized line data', function (): void {
    Currency::query()->where('code', 'AED')->update(['is_active' => true]);

    $actor = User::factory()->admin()->create();
    $supplier = Supplier::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create();
    $this->actingAs($actor);

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'purchase_cost' => '12.50',
        'currency_code' => 'AED',
        'is_active' => true,
    ]);

    $page = new ReflectionClass(CreatePurchaseOrder::class)->newInstanceWithoutConstructor();
    $create = new ReflectionMethod(CreatePurchaseOrder::class, 'handleRecordCreation');

    $order = $create->invoke($page, [
        'supplier_id' => (string) $supplier->getKey(),
        'currency_code' => 'AED',
        'ordered_at' => today()->toDateString(),
        'expected_at' => '',
        'notes' => 12345,
        'lines' => [
            'skip-me',
            [
                'product_variant_id' => (string) $variant->getKey(),
                'unit_id' => (string) $variant->unit_id,
                'quantity_ordered' => 2,
                'unit_cost' => '',
            ],
        ],
    ]);

    expect($order)->toBeInstanceOf(PurchaseOrder::class)
        ->and($order->supplier_id)->toBe($supplier->getKey())
        ->and($order->currency_code)->toBe('AED')
        ->and($order->expected_at)->toBeNull()
        ->and($order->notes)->toBe('12345')
        ->and($order->lines)->toHaveCount(1)
        ->and((string) $order->lines->first()->quantity_ordered)->toBe('2.000000');
});
