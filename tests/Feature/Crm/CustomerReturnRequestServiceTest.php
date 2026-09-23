<?php

declare(strict_types=1);

use App\Enums\CustomerReturnRequestStatus;
use App\Enums\InventoryReturnStatus;
use App\Enums\OperationStage;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CustomerReturnRequestService;
use App\Services\Crm\Exceptions\InvalidCustomerReturnRequestTransition;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse, ProductVariant, User}
 */
function completedDeliveryForReturnRequest(
    CustomerProfile $customer,
    string $startingQuantity = '10.000000',
    string $deliveredQuantity = '4.000000',
): array {
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => $startingQuantity,
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => $startingQuantity,
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => $startingQuantity,
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);
    $delivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
    ]);
    $line = $delivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => $deliveredQuantity,
        'unit_id' => $variant->unit_id,
        'inventory_lot_id' => $lot->getKey(),
    ]);

    $operations = app(InventoryOperationService::class);
    $operations->markReady($delivery, $actor);
    $operations->complete($delivery->refresh(), $actor);

    return [$delivery->refresh(), $line->refresh(), $warehouse, $variant, $actor];
}

it('submits a return request for lines belonging to the customer own completed delivery', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line] = completedDeliveryForReturnRequest($customer);

    $request = app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [[
            'original_inventory_operation_line_id' => $line->getKey(),
            'requested_quantity' => '2.000000',
            'customer_note' => 'Arrived damaged',
        ]],
        reason: 'Wrong item',
    );

    expect($request->status)->toBe(CustomerReturnRequestStatus::Submitted)
        ->and($request->customer_id)->toBe($customer->getKey())
        ->and($request->lines)->toHaveCount(1)
        ->and((float) $request->lines->first()->requested_quantity)->toBe(2.0);
});

it('refuses a return request from a customer who is not approved and active', function (): void {
    $customer = CustomerProfile::factory()->pending()->create();
    [$delivery, $line] = completedDeliveryForReturnRequest($customer);

    expect(fn () => app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    ))->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('refuses a delivery that does not belong to the customer', function (): void {
    $customer = CustomerProfile::factory()->create();
    $otherCustomer = CustomerProfile::factory()->create();
    [$delivery, $line] = completedDeliveryForReturnRequest($otherCustomer);

    expect(fn () => app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    ))->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('refuses a delivery that has not been completed yet', function (): void {
    $customer = CustomerProfile::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $warehouse = Warehouse::factory()->create();
    $draftDelivery = InventoryOperation::factory()->delivery()->create([
        'source_warehouse_id' => $warehouse->getKey(),
        'customer_id' => $customer->getKey(),
        'stage' => OperationStage::Draft,
    ]);
    $line = $draftDelivery->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => '2.000000',
        'unit_id' => $variant->unit_id,
    ]);

    expect(fn () => app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $draftDelivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    ))->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('refuses an empty line list', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery] = completedDeliveryForReturnRequest($customer);

    expect(fn () => app(CustomerReturnRequestService::class)->submit(customer: $customer, delivery: $delivery, lines: []))
        ->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('refuses a line whose delivery line belongs to a different delivery', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery] = completedDeliveryForReturnRequest($customer);
    [, $otherLine] = completedDeliveryForReturnRequest($customer);

    expect(fn () => app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $otherLine->getKey(), 'requested_quantity' => '1.000000']],
    ))->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('moves submitted through review to approved, and refuses out-of-order transitions', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line] = completedDeliveryForReturnRequest($customer);
    $actor = User::factory()->admin()->create();
    $service = app(CustomerReturnRequestService::class);

    $request = $service->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    );

    expect(fn () => $service->approve($actor, $request))->toThrow(InvalidCustomerReturnRequestTransition::class);

    $underReview = $service->startReview($actor, $request);
    expect($underReview->status)->toBe(CustomerReturnRequestStatus::UnderReview);

    $approved = $service->approve($actor, $underReview, 'Looks legitimate');
    expect($approved->status)->toBe(CustomerReturnRequestStatus::Approved)
        ->and($approved->review_note)->toBe('Looks legitimate');

    expect(fn () => $service->startReview($actor, $approved))->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('rejects a request from submitted, under review, or approved, but not once terminal', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line] = completedDeliveryForReturnRequest($customer);
    $actor = User::factory()->admin()->create();
    $service = app(CustomerReturnRequestService::class);

    $request = $service->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    );

    $rejected = $service->reject($actor, $request, 'Outside the return window');

    expect($rejected->status)->toBe(CustomerReturnRequestStatus::Rejected)
        ->and($rejected->review_note)->toBe('Outside the return window')
        ->and(fn () => $service->reject($actor, $rejected, 'again'))->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('converts an approved request into a draft inventory return without moving any stock', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line, $warehouse] = completedDeliveryForReturnRequest($customer, deliveredQuantity: '4.000000');
    $actor = User::factory()->admin()->create();
    $service = app(CustomerReturnRequestService::class);

    $request = $service->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '2.000000', 'customer_note' => 'Damaged']],
        reason: 'Wrong item',
    );
    $underReview = $service->startReview($actor, $request);
    $approved = $service->approve($actor, $underReview);

    $stockBefore = InventoryStock::query()
        ->where('product_variant_id', $line->product_variant_id)
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();

    $inventoryReturn = $service->convertToInventoryReturn($actor, $approved, $warehouse);

    $stockAfter = InventoryStock::query()
        ->where('product_variant_id', $line->product_variant_id)
        ->where('warehouse_id', $warehouse->getKey())
        ->sole();

    expect($inventoryReturn->status)->toBe(InventoryReturnStatus::Draft)
        ->and($inventoryReturn->lines)->toHaveCount(1)
        ->and((float) $inventoryReturn->lines->first()->base_quantity)->toBe(2.0)
        ->and((float) $stockAfter->on_hand_quantity)->toBe((float) $stockBefore->on_hand_quantity)
        ->and($approved->refresh()->status)->toBe(CustomerReturnRequestStatus::Converted)
        ->and($approved->resulting_inventory_return_id)->toBe($inventoryReturn->getKey());
});

it('refuses conversion of a request that is not yet approved', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line, $warehouse] = completedDeliveryForReturnRequest($customer);
    $actor = User::factory()->admin()->create();
    $service = app(CustomerReturnRequestService::class);

    $request = $service->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '1.000000']],
    );

    expect(fn () => $service->convertToInventoryReturn($actor, $request, $warehouse))
        ->toThrow(InvalidCustomerReturnRequestTransition::class);
});

it('surfaces the existing InventoryReturnService validation instead of duplicating it, when the requested quantity exceeds what is deliverable', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line, $warehouse] = completedDeliveryForReturnRequest($customer, deliveredQuantity: '4.000000');
    $actor = User::factory()->admin()->create();
    $service = app(CustomerReturnRequestService::class);

    $request = $service->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [['original_inventory_operation_line_id' => $line->getKey(), 'requested_quantity' => '999.000000']],
    );
    $underReview = $service->startReview($actor, $request);
    $approved = $service->approve($actor, $underReview);

    expect(fn () => $service->convertToInventoryReturn($actor, $approved, $warehouse))
        ->toThrow(DomainException::class);

    expect($approved->refresh()->status)->toBe(CustomerReturnRequestStatus::Approved);
});
it('refuses a return request with a non-positive requested quantity', function (): void {
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line] = completedDeliveryForReturnRequest($customer);

    expect(fn () => app(CustomerReturnRequestService::class)->submit(
        customer: $customer,
        delivery: $delivery,
        lines: [[
            'original_inventory_operation_line_id' => $line->getKey(),
            'requested_quantity' => '0.000000',
        ]],
    ))->toThrow(
        InvalidCustomerReturnRequestTransition::class,
        'requested quantity must be positive',
    );
});
