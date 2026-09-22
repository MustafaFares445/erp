<?php

declare(strict_types=1);

use App\Data\Inventory\LogisticsInboundBlockerData;
use App\Data\Inventory\LogisticsInboundData;
use App\Enums\OperationStage;
use App\Enums\PurchaseInboundStatus;
use App\Filament\Pages\LogisticsOutboundQueue;
use App\Filament\Pages\ReceivingExceptions;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\Order;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('covers outbound queue lifecycle callbacks labels source order and actor guard', function (): void {
    $actor = User::factory()->admin()->create();
    $this->actingAs($actor);

    $service = new class
    {
        public int $readyCalls = 0;

        public int $completeCalls = 0;

        public function markReady(InventoryOperation $operation, User $actor): InventoryOperation
        {
            $this->readyCalls++;

            return $operation;
        }

        public function complete(InventoryOperation $operation, User $actor): InventoryOperation
        {
            $this->completeCalls++;

            return $operation;
        }
    };
    app()->instance(InventoryOperationService::class, $service);

    $operation = InventoryOperation::factory()->delivery()->draft()->create();

    $actionsMethod = new ReflectionMethod(LogisticsOutboundQueue::class, 'lifecycleActions');
    $actions = collect($actionsMethod->invoke(null))
        ->keyBy(static fn ($action): string => $action->getName());

    $actions->get('markReady')->getActionFunction()($operation);
    $actions->get('dispatch')->getActionFunction()($operation);

    expect($service->readyCalls)->toBe(1)
        ->and($service->completeCalls)->toBe(1);

    $sourceOrder = new ReflectionMethod(LogisticsOutboundQueue::class, 'sourceOrder');
    $order = Order::factory()->create();
    $operation->setRelation('sourceDocument', $order);
    expect($sourceOrder->invoke(null, $operation))->toBe($order->order_number);

    $nextAction = new ReflectionMethod(LogisticsOutboundQueue::class, 'nextAction');

    $picked = new InventoryOperationLine;
    $picked->forceFill(['is_picked' => true]);

    $operation->forceFill(['stage' => OperationStage::Draft]);
    $operation->setRelation('lines', new Collection([$picked]));

    expect($nextAction->invoke(null, $operation))->toBe(__('admin.logistics.actions.mark_ready'));

    $unpicked = new InventoryOperationLine;
    $unpicked->forceFill(['is_picked' => false]);

    $operation->setRelation('lines', new Collection([$unpicked]));
    expect($nextAction->invoke(null, $operation))->toBe(__('admin.logistics.actions.prepare_pick'));

    $operation->forceFill(['stage' => OperationStage::Waiting]);
    expect($nextAction->invoke(null, $operation))->toBe(__('admin.logistics.actions.resolve_stock_shortage'));

    auth()->logout();
    $actorMethod = new ReflectionMethod(LogisticsOutboundQueue::class, 'actor');
    expect(fn (): mixed => $actorMethod->invoke(null))
        ->toThrow(LogicException::class, 'authenticated warehouse actor');
});

it('covers receiving exception overdue open-receipt partial-state and color helpers', function (): void {
    $receipt = InventoryOperation::factory()->receipt()->draft()->create();

    $purchaseOrder = new PurchaseOrder;
    $purchaseOrder->setRelation('receipts', new Collection([$receipt]));

    $inbound = new PurchaseInbound;
    $inbound->forceFill(['id' => 987654]);
    $inbound->setRelation('purchaseOrder', $purchaseOrder);

    $projection = new LogisticsInboundData(
        purchaseInboundId: 987654,
        purchaseOrderId: 123,
        purchaseOrderReference: 'PO-COVERAGE',
        supplier: 'Coverage supplier',
        expectedAt: now()->subDay(),
        inboundStatus: PurchaseInboundStatus::AwaitingReceipt,
        businessState: 'Partially Received',
        overdue: true,
        confirmedBaseQuantity: '10.000000',
        allocatedBaseQuantity: '10.000000',
        receivedBaseQuantity: '5.000000',
        remainingBaseQuantity: '5.000000',
        destinationWarehouses: [],
        blockers: [new LogisticsInboundBlockerData('coverage', 'Coverage blocker')],
        lines: [],
        nextAction: 'Complete receipt',
    );

    $projectionService = new readonly class($projection)
    {
        public function __construct(private LogisticsInboundData $projection) {}

        public function project(PurchaseInbound $record): LogisticsInboundData
        {
            return $this->projection;
        }
    };
    app()->instance(LogisticsInboundProjectionService::class, $projectionService);

    $exceptions = new ReflectionMethod(ReceivingExceptions::class, 'exceptions');
    $values = $exceptions->invoke(null, $inbound);

    expect($values)
        ->toContain('Coverage blocker')
        ->toContain(__('admin.logistics.exceptions.overdue_expected_inbound'))
        ->toContain(__('admin.logistics.exceptions.open_draft_receipt'))
        ->toContain(__('admin.logistics.exceptions.partial_receipt_remaining'));

    $color = new ReflectionMethod(ReceivingExceptions::class, 'stateColor');
    expect($color->invoke(null, 'Needs Attention'))->toBe('danger')
        ->and($color->invoke(null, 'Ready to Receive'))->toBe('info')
        ->and($color->invoke(null, 'Partially Received'))->toBe('info');
});

it('covers receiving exceptions page metadata access table and fallback branches', function (): void {
    Gate::before(static fn (): bool => true);

    $actor = User::factory()->admin()->create();
    $inbound = PurchaseInbound::factory()->awaitingReceipt()->create();

    $projection = new LogisticsInboundData(
        purchaseInboundId: (int) $inbound->getKey(),
        purchaseOrderId: (int) $inbound->purchase_order_id,
        purchaseOrderReference: (string) $inbound->purchaseOrder->purchase_order_number,
        supplier: (string) $inbound->purchaseOrder->supplier->name,
        expectedAt: now()->addDay(),
        inboundStatus: PurchaseInboundStatus::AwaitingReceipt,
        businessState: 'Awaiting Allocation',
        overdue: false,
        confirmedBaseQuantity: '1.000000',
        allocatedBaseQuantity: '0.000000',
        receivedBaseQuantity: '0.000000',
        remainingBaseQuantity: '1.000000',
        destinationWarehouses: [],
        blockers: [],
        lines: [],
        nextAction: 'Allocate warehouse quantities',
    );

    app()->instance(LogisticsInboundProjectionService::class, new readonly class($projection)
    {
        public function __construct(private LogisticsInboundData $projection) {}

        public function project(PurchaseInbound $record): LogisticsInboundData
        {
            return $this->projection;
        }
    });

    auth()->logout();
    expect(ReceivingExceptions::canAccess())->toBeFalse()
        ->and(ReceivingExceptions::getNavigationLabel())->toBe(__('admin.resources.receiving_exceptions'));

    $this->actingAs($actor);

    expect(ReceivingExceptions::canAccess())->toBeTrue()
        ->and(app(ReceivingExceptions::class)->getTitle())->toBe(__('admin.resources.receiving_exceptions'));

    $component = Livewire::actingAs($actor)
        ->test(ReceivingExceptions::class)
        ->assertCanSeeTableRecords([$inbound])
        ->assertTableColumnStateSet('exception_state', 'Awaiting Allocation', $inbound)
        ->assertTableColumnStateSet('exceptions', [__('admin.logistics.exceptions.review_inbound')], $inbound)
        ->assertTableColumnStateSet('next_action', 'Allocate warehouse quantities', $inbound);

    $completeReceipt = collect($component->instance()->getTable()->getRecordActions())
        ->first(static fn ($action): bool => $action->getName() === 'completeReceipt');

    expect($completeReceipt)->not->toBeNull()
        ->and($completeReceipt->record($inbound)->getUrl())->toBeNull();

    $receipt = InventoryOperation::factory()->receipt()->draft()->create([
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $inbound->purchase_order_id,
    ]);

    $inboundWithReceipt = $inbound->fresh('purchaseOrder.receipts');
    expect($completeReceipt->record($inboundWithReceipt)->getUrl())
        ->toContain((string) $receipt->getKey());

    $openReceipt = new ReflectionMethod(ReceivingExceptions::class, 'openReceipt');
    expect($openReceipt->invoke(null, $inboundWithReceipt))->toBeInstanceOf(InventoryOperation::class);

    $color = new ReflectionMethod(ReceivingExceptions::class, 'stateColor');
    expect($color->invoke(null, 'Awaiting Allocation'))->toBe('warning')
        ->and($color->invoke(null, 'Other'))->toBe('gray');
});
