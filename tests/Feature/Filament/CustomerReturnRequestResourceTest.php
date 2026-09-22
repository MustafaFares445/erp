<?php

declare(strict_types=1);

use App\Enums\CustomerReturnRequestStatus;
use App\Filament\Resources\CustomerReturnRequests\Pages\CreateCustomerReturnRequest;
use App\Filament\Resources\CustomerReturnRequests\Pages\ListCustomerReturnRequests;
use App\Filament\Resources\CustomerReturnRequests\Pages\ViewCustomerReturnRequest;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CustomerReturnRequestService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{InventoryOperation, int, Warehouse}
 */
function completedDeliveryForReturnRequestResourceTest(CustomerProfile $customer): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '10.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '10.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $line = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '4.000000',
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    $lineKey = $line->getKey();

    return [$delivery->refresh(), is_int($lineKey) ? $lineKey : 0, $warehouse];
}

it('submits a return request on behalf of a customer from the dashboard', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    [$delivery, $lineId] = completedDeliveryForReturnRequestResourceTest($customer);

    Livewire::actingAs($admin)
        ->test(CreateCustomerReturnRequest::class)
        ->fillForm([
            'customer_id' => $customer->getKey(),
            'original_inventory_operation_id' => $delivery->getKey(),
            'reason' => 'Wrong item delivered.',
            'lines' => [
                ['original_inventory_operation_line_id' => $lineId, 'requested_quantity' => 2],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = CustomerReturnRequest::query()->sole();

    expect($request->customer_id)->toBe($customer->getKey())
        ->and($request->lines)->toHaveCount(1);
});

it('lists return requests and shows their status', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    [$delivery, $lineId] = completedDeliveryForReturnRequestResourceTest($customer);

    $request = app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $lineId, 'requested_quantity' => '1.000000']],
    );

    Livewire::actingAs($admin)
        ->test(ListCustomerReturnRequests::class)
        ->assertCanSeeTableRecords([$request]);
});

it('runs the full start review, approve and convert cycle from the view page', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    [$delivery, $lineId, $warehouse] = completedDeliveryForReturnRequestResourceTest($customer);

    $request = app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $lineId, 'requested_quantity' => '2.000000']],
    );

    Livewire::actingAs($admin)
        ->test(ViewCustomerReturnRequest::class, ['record' => $request->getKey()])
        ->callAction('startReview')
        ->assertNotified();

    expect($request->refresh()->status)->toBe(CustomerReturnRequestStatus::UnderReview);

    Livewire::actingAs($admin)
        ->test(ViewCustomerReturnRequest::class, ['record' => $request->getKey()])
        ->callAction('approveAndConvert', data: ['warehouse_id' => $warehouse->getKey()])
        ->assertNotified();

    expect($request->refresh()->status)->toBe(CustomerReturnRequestStatus::Converted)
        ->and($request->resulting_inventory_return_id)->not->toBeNull();
});

it('rejects a return request from the view page', function (): void {
    $admin = User::factory()->admin()->create();
    $customer = CustomerProfile::factory()->create();
    [$delivery, $lineId] = completedDeliveryForReturnRequestResourceTest($customer);

    $request = app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $lineId, 'requested_quantity' => '1.000000']],
    );

    Livewire::actingAs($admin)
        ->test(ViewCustomerReturnRequest::class, ['record' => $request->getKey()])
        ->callAction('rejectReturnRequest', data: ['reason' => 'Outside the return window'])
        ->assertNotified();

    expect($request->refresh()->status)->toBe(CustomerReturnRequestStatus::Rejected);
});
