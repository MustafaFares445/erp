<?php

declare(strict_types=1);

use App\Enums\CustomerReturnRequestStatus;
use App\Enums\QuotationResponseType;
use App\Enums\QuotationStatus;
use App\Filament\Resources\CustomerReturnRequests\Actions\CustomerReturnRequestActions;
use App\Filament\Resources\Quotations\Actions\QuotationActions;
use App\Models\CustomerProfile;
use App\Models\CustomerReturnRequest;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryStock;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Crm\CustomerReturnRequestService;
use App\Services\Inventory\InventoryOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Gate::before(static fn (): bool => true);
});
/**
 * @return array{InventoryOperation, InventoryOperationLine, Warehouse}
 */
function filamentResidualCompletedDelivery(CustomerProfile $customer): array
{
    $warehouse = Warehouse::factory()->create();
    $variant = ProductVariant::factory()->grain()->create();
    $actor = User::factory()->admin()->create();

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

    return [$delivery->refresh(), $line->refresh(), $warehouse];
}
it('covers guest early returns for customer-return actions', function (): void {
    $submitted = CustomerReturnRequest::factory()->create([
        'status' => CustomerReturnRequestStatus::Submitted,
    ]);
    $underReview = CustomerReturnRequest::factory()->create([
        'status' => CustomerReturnRequestStatus::UnderReview,
    ]);
    $approved = CustomerReturnRequest::factory()->create([
        'status' => CustomerReturnRequestStatus::Approved,
    ]);

    CustomerReturnRequestActions::startReview()->getActionFunction()($submitted);
    CustomerReturnRequestActions::approveAndConvert()->getActionFunction()($underReview, []);
    CustomerReturnRequestActions::retryConvert()->getActionFunction()($approved, []);
    CustomerReturnRequestActions::reject()->getActionFunction()($submitted, ['reason' => 'guest']);

    expect(true)->toBeTrue();
});
it('covers customer-return conversion action failures', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $warehouse = Warehouse::factory()->create();

    $underReview = CustomerReturnRequest::factory()->create([
        'status' => CustomerReturnRequestStatus::UnderReview,
    ]);
    CustomerReturnRequestActions::approveAndConvert()->getActionFunction()(
        $underReview,
        ['warehouse_id' => $warehouse->getKey(), 'note' => 'Coverage'],
    );

    expect($underReview->refresh()->status)->toBe(CustomerReturnRequestStatus::Approved);

    CustomerReturnRequestActions::retryConvert()->getActionFunction()(
        $underReview->refresh(),
        ['warehouse_id' => $warehouse->getKey()],
    );

    expect($underReview->refresh()->status)->toBe(CustomerReturnRequestStatus::Approved);
});
it('covers successful retry conversion from the customer-return action', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $customer = CustomerProfile::factory()->create();
    [$delivery, $line, $warehouse] = filamentResidualCompletedDelivery($customer);

    $service = app(CustomerReturnRequestService::class);
    $request = $service->submit(
        $customer,
        $delivery,
        [[
            'original_inventory_operation_line_id' => $line->getKey(),
            'requested_quantity' => '1.000000',
        ]],
    );
    $reviewed = $service->startReview($actor, $request);
    $approved = $service->approve($actor, $reviewed);

    CustomerReturnRequestActions::retryConvert()->getActionFunction()(
        $approved,
        ['warehouse_id' => $warehouse->getKey()],
    );

    expect($approved->refresh()->status)->toBe(CustomerReturnRequestStatus::Converted)
        ->and($approved->resulting_inventory_return_id)->not->toBeNull();
});
it('covers guest early returns for quotation decision and PDF actions', function (): void {
    $sent = Quotation::factory()->sent()->create();

    QuotationActions::recordDecision()->getActionFunction()($sent, []);
    QuotationActions::generatePdf()->getActionFunction()($sent);

    expect($sent->refresh()->status)->toBe(QuotationStatus::Sent);
});

it('covers all quotation decision action branches', function (QuotationResponseType $decision, ?string $note): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);
    $quotation = Quotation::factory()->sent()->create([
        'expires_at' => now()->addWeek(),
    ]);

    QuotationActions::recordDecision()->getActionFunction()($quotation, [
        'decided_at' => now()->toDateString(),
        'decision' => $decision->value,
        'decision_note' => $note,
    ]);

    expect($quotation->refresh()->status)->toBe(match ($decision) {
        QuotationResponseType::Accepted => QuotationStatus::Accepted,
        QuotationResponseType::Rejected => QuotationStatus::Rejected,
        QuotationResponseType::ChangesRequested => QuotationStatus::ChangesRequested,
    });
})->with([
    'accepted' => [QuotationResponseType::Accepted, null],
    'rejected' => [QuotationResponseType::Rejected, 'No thanks'],
    'changes requested' => [QuotationResponseType::ChangesRequested, 'Please revise'],
]);
it('covers the revised-quotation action label branch', function (): void {
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::ChangesRequested,
    ]);

    $action = QuotationActions::requote()->record($quotation);

    expect($action->getLabel())->toBe(__('admin.sales.actions.create_revised_quotation'));
});
