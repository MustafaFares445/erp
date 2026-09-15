<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\PurchaseInboundStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Bill;
use App\Models\InventoryOperation;
use App\Models\PurchaseInbound;
use App\Models\PurchaseOrder;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\InventoryDemoSeeder;
use Database\Seeders\PurchasePermissionSeeder;
use Database\Seeders\PurchasingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds connected purchase receiving and draft bill projections idempotently', function (): void {
    (new CurrencySeeder)->run();
    (new ChartOfAccountsSeeder)->run();
    (new PurchasePermissionSeeder)->run();
    (new InventoryDemoSeeder)->run();

    $seeder = new PurchasingDemoSeeder;
    $seeder->run();
    $seeder->run();

    $partialOrder = PurchaseOrder::query()->where('purchase_order_number', 'PO-DEMO06')->sole();
    $receivedOrder = PurchaseOrder::query()->where('purchase_order_number', 'PO-DEMO07')->sole();
    $closedOrder = PurchaseOrder::query()->where('purchase_order_number', 'PO-DEMO08')->sole();
    $partialInbound = PurchaseInbound::query()->where('purchase_order_id', $partialOrder->getKey())->sole();

    expect($partialOrder->status)->toBe(PurchaseOrderStatus::PartiallyReceived)
        ->and($receivedOrder->status)->toBe(PurchaseOrderStatus::Received)
        ->and($closedOrder->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($partialInbound->status)
        ->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and(PurchaseInbound::query()->where('purchase_order_id', $receivedOrder->getKey())->sole()->status)
        ->toBe(PurchaseInboundStatus::Received)
        ->and(PurchaseInbound::query()->where('purchase_order_id', $closedOrder->getKey())->sole()->status)
        ->toBe(PurchaseInboundStatus::PartiallyReceived)
        ->and(InventoryOperation::query()
            ->where('operation_type', 'receipt')
            ->where('source_document_type', PurchaseOrder::class)
            ->whereIn('source_document_id', [
                $partialOrder->getKey(),
                $receivedOrder->getKey(),
                $closedOrder->getKey(),
            ])
            ->where('stage', 'done')
            ->count())->toBe(3)
        ->and(Bill::query()
            ->where('purchase_order_id', PurchaseOrder::query()
                ->where('purchase_order_number', 'PO-DEMO05')
                ->value('id'))
            ->sole()
            ->status)->toBe(BillStatus::Draft)
        ->and(app(LogisticsInboundProjectionService::class)->project($partialInbound)->businessState)
        ->toBe('Partially Received');
});
