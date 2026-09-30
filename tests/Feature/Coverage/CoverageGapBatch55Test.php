<?php

declare(strict_types=1);

use App\Data\Purchasing\PurchaseOrderWorkflowData;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\ShipmentAttachments\Schemas\ShipmentAttachmentInfolist;
use App\Filament\Widgets\PurchasingAttentionQueue;
use App\Models\Bill;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentArrivalConfirmation;
use App\Models\SupplierConfirmation;
use App\Services\Purchasing\PurchaseOrderWorkflowService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function coverage55Workflow(
    string $nextOwner = 'Purchasing',
    ?string $blocker = null,
    string $businessState = 'Coverage state',
): PurchaseOrderWorkflowData {
    return new PurchaseOrderWorkflowData(
        businessState: $businessState,
        supplierState: 'Confirmed',
        logisticsState: 'Coverage',
        financialState: 'Coverage',
        orderedBaseQuantity: '1.000000',
        confirmedBaseQuantity: '1.000000',
        backorderedBaseQuantity: '0.000000',
        unavailableBaseQuantity: '0.000000',
        allocatedBaseQuantity: '1.000000',
        receiptInProgressBaseQuantity: '0.000000',
        receivedBaseQuantity: '0.000000',
        remainingConfirmedBaseQuantity: '1.000000',
        billTotal: '0.00',
        paidTotal: '0.00',
        outstandingTotal: '0.00',
        blocker: $blocker,
        nextOwner: $nextOwner,
        nextAction: 'Review',
    );
}

function bindCoverage55Workflow(PurchaseOrderWorkflowData $projection): void
{
    app()->instance(PurchaseOrderWorkflowService::class, new readonly class($projection)
    {
        public function __construct(private PurchaseOrderWorkflowData $projection) {}

        public function project(PurchaseOrder $record): PurchaseOrderWorkflowData
        {
            return $this->projection;
        }
    });
}

it('maps shipment arrival-confirmation media rows', function (): void {
    Storage::fake('local');

    $shipment = Shipment::factory()->create();
    $confirmation = ShipmentArrivalConfirmation::factory()->create([
        'shipment_id' => $shipment->getKey(),
    ]);

    $confirmation
        ->addMedia(UploadedFile::fake()->image('arrival-proof.jpg'))
        ->toMediaCollection('delivery-confirmation-photos');

    $shipment->setRelation('arrivalConfirmation', $confirmation->refresh());

    $method = new ReflectionMethod(ShipmentAttachmentInfolist::class, 'deliveryPhotoRows');
    $rows = $method->invoke(null, $shipment);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['file_name'])->toBe('arrival-proof.jpg')
        ->and($rows[0]['preview_url'])->toContain((string) $shipment->getKey())
        ->and($rows[0]['download_url'])->toContain((string) $shipment->getKey());
});

it('covers purchasing attention reason and color branches', function (): void {
    $reason = new ReflectionMethod(PurchasingAttentionQueue::class, 'attentionReason');
    $color = new ReflectionMethod(PurchasingAttentionQueue::class, 'attentionColor');
    $overdue = new ReflectionMethod(PurchasingAttentionQueue::class, 'isOverdue');

    $late = PurchaseOrder::factory()->create([
        'status' => PurchaseOrderStatus::Accepted->value,
        'expected_at' => today()->subDay(),
    ]);

    expect($overdue->invoke(null, $late))->toBeTrue()
        ->and($reason->invoke(null, $late))->toBe('Overdue delivery')
        ->and($color->invoke(null, $late))->toBe('danger');

    $normal = PurchaseOrder::factory()->create([
        'status' => PurchaseOrderStatus::Accepted->value,
        'expected_at' => today()->addDay(),
    ]);

    bindCoverage55Workflow(coverage55Workflow(blocker: null, businessState: 'Awaiting response'));

    expect($overdue->invoke(null, $normal))->toBeFalse()
        ->and($reason->invoke(null, $normal))->toBe('Awaiting response')
        ->and($color->invoke(null, $normal))->toBe('info');

    bindCoverage55Workflow(coverage55Workflow(blocker: 'Supplier response pending'));

    expect($reason->invoke(null, $normal))->toBe('Supplier response pending')
        ->and($color->invoke(null, $normal))->toBe('warning');

    $terminal = PurchaseOrder::factory()->create([
        'status' => PurchaseOrderStatus::Closed->value,
        'expected_at' => today()->subDay(),
    ]);

    expect($overdue->invoke(null, $terminal))->toBeFalse();
});

it('covers every purchasing attention action URL destination', function (): void {
    $url = new ReflectionMethod(PurchasingAttentionQueue::class, 'actionUrl');

    $inventoryOrder = PurchaseOrder::factory()->create();
    $inbound = PurchaseInbound::factory()->create(['purchase_order_id' => $inventoryOrder->getKey()]);
    $inventoryOrder->setRelation('purchaseInbound', $inbound);
    bindCoverage55Workflow(coverage55Workflow(nextOwner: 'Inventory'));

    expect($url->invoke(null, $inventoryOrder))->toContain((string) $inbound->getKey());

    $accountingOrder = PurchaseOrder::factory()->create();
    $bill = Bill::factory()->forPurchaseOrder($accountingOrder)->create();
    $accountingOrder->setRelation('bills', new Collection([$bill]));
    bindCoverage55Workflow(coverage55Workflow(nextOwner: 'Accounting'));

    expect($url->invoke(null, $accountingOrder))->toContain((string) $bill->getKey());

    $purchasingOrder = PurchaseOrder::factory()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $purchasingOrder->getKey(),
        'supplier_id' => $purchasingOrder->supplier_id,
        'confirmation_status' => 'pending',
    ]);
    $purchasingOrder->setRelation('confirmations', new Collection([$confirmation]));
    bindCoverage55Workflow(coverage55Workflow(nextOwner: 'Purchasing'));

    expect($url->invoke(null, $purchasingOrder))->toContain((string) $confirmation->getKey());

    $fallbackOrder = PurchaseOrder::factory()->create();
    $fallbackOrder->setRelation('purchaseInbound', null);
    $fallbackOrder->setRelation('bills', new Collection);
    $fallbackOrder->setRelation('confirmations', new Collection);
    bindCoverage55Workflow(coverage55Workflow(nextOwner: 'Other'));

    expect($url->invoke(null, $fallbackOrder))->toContain((string) $fallbackOrder->getKey());
});
