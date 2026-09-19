<?php

declare(strict_types=1);

use App\Enums\OperationStage;
use App\Enums\OrderStatus;
use App\Enums\SalesPermission;
use App\Models\CustomerProfile;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Sales\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function soDeepService(): SalesOrderService
{
    return app(SalesOrderService::class);
}

function soDeepActor(): User
{
    $permissions = [
        SalesPermission::OrderCreate->value,
        SalesPermission::OrderManage->value,
        SalesPermission::OrderConfirm->value,
        SalesPermission::OrderRelease->value,
        SalesPermission::OrderCancel->value,
        SalesPermission::OrderClose->value,
    ];
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $role = Role::findOrCreate('coverage-sales-order-service', 'web');
    $role->syncPermissions($permissions);

    $actor = User::factory()->create();
    $actor->assignRole($role);

    return $actor;
}

/** @return array<string, mixed> */
function soDeepLine(ProductVariant $variant, float $quantity = 1.0): array
{
    return [
        'product_variant_id' => $variant->getKey(),
        'quantity' => $quantity,
        'unit_id' => $variant->unit_id,
        'unit_price' => 100,
        'tax_amount' => 5,
    ];
}

it('covers draft creation update confirmation and release', function (): void {
    $actor = soDeepActor();
    $customer = CustomerProfile::factory()->create(['is_active' => true]);
    $otherCustomer = CustomerProfile::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create();
    $service = soDeepService();

    $order = $service->createDraft(
        $actor,
        ['customer_id' => $customer->getKey(), 'notes' => 'Initial'],
        [soDeepLine($variant)],
    );
    expect($order->status)->toBe(OrderStatus::Draft)
        ->and($order->lines)->toHaveCount(1);

    $order = $service->updateDraft(
        $actor,
        $order,
        ['customer_id' => $otherCustomer->getKey(), 'notes' => 'Updated'],
        [soDeepLine($variant, 2)],
    );
    expect($order->customer_id)->toBe($otherCustomer->getKey())
        ->and($order->notes)->toBe('Updated')
        ->and($order->lines)->toHaveCount(1);

    $order = $service->confirm($actor, $order);
    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->confirmed_at)->not->toBeNull();

    $order = $service->release($actor, $order);
    expect($order->status)->toBe(OrderStatus::Released)
        ->and($order->released_at)->not->toBeNull();
});

it('rejects invalid draft customers lines variants and quantities', function (): void {
    $actor = soDeepActor();
    $service = soDeepService();
    $customer = CustomerProfile::factory()->create(['is_active' => true]);
    $inactive = CustomerProfile::factory()->create(['is_active' => false]);
    $variant = ProductVariant::factory()->create();

    expect(fn (): Order => $service->createDraft($actor, ['customer_id' => 'bad'], [soDeepLine($variant)]))
        ->toThrow(ValidationException::class, 'Select an active customer')
        ->and(fn (): Order => $service->createDraft($actor, ['customer_id' => $inactive->getKey()], [soDeepLine($variant)]))
        ->toThrow(ValidationException::class, 'Select an active customer')
        ->and(fn (): Order => $service->createDraft($actor, ['customer_id' => $customer->getKey()], []))
        ->toThrow(ValidationException::class, 'Add at least one product');

    expect(fn (): Order => $service->createDraft($actor, ['customer_id' => $customer->getKey()], [[
        'product_variant_id' => 999999,
        'quantity' => 1,
    ]]))->toThrow(ValidationException::class, 'product variants are unavailable');

    expect(fn (): Order => $service->createDraft($actor, ['customer_id' => $customer->getKey()], [[
        'product_variant_id' => $variant->getKey(),
        'quantity' => 0,
    ]]))->toThrow(ValidationException::class, 'positive quantity');

    expect(fn (): Order => $service->createDraft($actor, ['customer_id' => $customer->getKey()], [[
        'product_variant_id' => 'invalid',
        'quantity' => 1,
    ]]))->toThrow(DomainException::class, 'Expected an integer value')
        ->and(fn (): Order => $service->createDraft($actor, ['customer_id' => $customer->getKey()], [[
            'product_variant_id' => $variant->getKey(),
            'quantity' => 1,
            'unit_id' => 'invalid',
        ]]))->toThrow(DomainException::class, 'Expected an integer value');
});

it('covers update validation and commercial completeness guards', function (): void {
    $actor = soDeepActor();
    $service = soDeepService();
    $customer = CustomerProfile::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create();
    $draft = $service->createDraft($actor, ['customer_id' => $customer->getKey()], [soDeepLine($variant)]);

    expect(fn (): Order => $service->updateDraft($actor, $draft, [], []))
        ->toThrow(ValidationException::class, 'Add at least one product');

    $inactiveCustomer = CustomerProfile::factory()->create(['is_active' => false]);
    $inactiveOrder = Order::factory()->draft()->for($inactiveCustomer, 'customer')->create();
    expect(fn (): Order => $service->confirm($actor, $inactiveOrder))
        ->toThrow(ValidationException::class, 'active customer');

    $emptyOrder = Order::factory()->draft()->for($customer, 'customer')->create();
    expect(fn (): Order => $service->confirm($actor, $emptyOrder))
        ->toThrow(ValidationException::class, 'at least one line');

    $incomplete = $service->createDraft($actor, ['customer_id' => $customer->getKey()], [soDeepLine($variant)]);
    $lineId = $incomplete->lines->sole()->getKey();
    DB::table('order_lines')->where('id', $lineId)->update(['line_total' => null]);
    expect(fn (): Order => $service->confirm($actor, $incomplete))
        ->toThrow(ValidationException::class, 'frozen UOM');
});

it('covers short close validation partial closure and final close', function (): void {
    $actor = soDeepActor();
    $service = soDeepService();
    $customer = CustomerProfile::factory()->create(['is_active' => true]);
    $variant = ProductVariant::factory()->create();
    $order = Order::factory()->for($customer, 'customer')->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 2,
        'unit_id' => $variant->unit_id,
    ]);

    expect(fn (): Order => $service->shortClose($actor, $order, [$line->getKey() => 1], ''))
        ->toThrow(ValidationException::class, 'reason is required')
        ->and(fn (): Order => $service->shortClose($actor, $order, [$line->getKey() => 'bad'], 'Coverage'))
        ->toThrow(ValidationException::class, 'non-negative');

    expect(fn (): Order => $service->shortClose($actor, $order, [999999 => 1], 'Coverage'))
        ->toThrow(ValidationException::class, 'selected order line is invalid')
        ->and(fn (): Order => $service->shortClose($actor, $order, [$line->getKey() => 3], 'Coverage'))
        ->toThrow(ValidationException::class, 'exceeds the remaining');

    $order = $service->shortClose($actor, $order, [$line->getKey() => 1], 'Customer reduced demand');
    expect((float) $order->lines->sole()->short_closed_base_quantity)->toBe(1.0);

    expect(fn (): Order => $service->close($actor, $order, 'Too early'))
        ->toThrow(DomainException::class, 'still has unplanned fulfillment quantity');

    $order = $service->shortClose($actor, $order, [$line->getKey() => 1], 'Close remainder');
    $order = $service->close($actor, $order, 'Demand fully resolved');

    expect($order->status)->toBe(OrderStatus::Closed)
        ->and($order->closed_at)->not->toBeNull();
});

it('blocks cancellation with active logistics and cancels after logistics is canceled', function (): void {
    $actor = soDeepActor();
    $service = soDeepService();
    $order = Order::factory()->create();

    expect(fn (): Order => $service->cancel($actor, $order, ''))
        ->toThrow(ValidationException::class, 'cancellation reason is required');

    $delivery = InventoryOperation::factory()->delivery()->ready()->create([
        'customer_id' => $order->customer_id,
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);

    expect(fn (): Order => $service->cancel($actor, $order, 'Customer cancelled'))
        ->toThrow(DomainException::class, 'Resolve or cancel Logistics');

    $delivery->forceFill([
        'stage' => OperationStage::Canceled,
        'canceled_at' => now(),
    ])->save();
    $order = $service->cancel($actor, $order, 'Customer cancelled');

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancelled_at)->not->toBeNull();
});

it('covers planned quantities and scalar helper guards', function (): void {
    $service = soDeepService();
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $orderLine = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 2,
        'unit_id' => $variant->unit_id,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->ready()->create([
        'customer_id' => $order->customer_id,
        'source_document_type' => Order::class,
        'source_document_id' => $order->getKey(),
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '0.250000',
        'unit_id' => $variant->unit_id,
        'order_line_id' => null,
    ]);
    $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '0.500000',
        'unit_id' => $variant->unit_id,
        'order_line_id' => $orderLine->getKey(),
    ]);

    $plannedMethod = new ReflectionMethod($service, 'plannedBaseByOrderLine');
    expect($plannedMethod->invoke($service, $order))->toBe([$orderLine->getKey() => 0.5]);

    $integerMethod = new ReflectionMethod($service, 'integerInput');
    expect($integerMethod->invoke($service, '42'))->toBe(42)
        ->and(fn (): mixed => $integerMethod->invoke($service, '4.2'))
        ->toThrow(DomainException::class, 'Expected an integer value');

    $numericMethod = new ReflectionMethod($service, 'numericInput');
    expect($numericMethod->invoke($service, '1.25'))->toBe(1.25)
        ->and(fn (): mixed => $numericMethod->invoke($service, []))
        ->toThrow(DomainException::class, 'Expected a numeric value');

    $statusMethod = new ReflectionMethod($service, 'assertStatus');
    expect(fn (): mixed => $statusMethod->invoke($service, $order, OrderStatus::Draft))
        ->toThrow(DomainException::class, 'Sales order must be Draft');

    $activeCustomerMethod = new ReflectionMethod($service, 'activeCustomer');
    $active = CustomerProfile::factory()->create(['is_active' => true]);
    $inactive = CustomerProfile::factory()->create(['is_active' => false]);
    expect($activeCustomerMethod->invoke($service, $active->getKey())->is($active))->toBeTrue()
        ->and(fn (): mixed => $activeCustomerMethod->invoke($service, null))
        ->toThrow(ValidationException::class, 'Select an active customer')
        ->and(fn (): mixed => $activeCustomerMethod->invoke($service, $inactive->getKey()))
        ->toThrow(ValidationException::class, 'Select an active customer');
});
