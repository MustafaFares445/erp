<?php

declare(strict_types=1);

use App\Enums\AccountingPermission;
use App\Enums\BillStatus;
use App\Enums\InventoryPermission;
use App\Enums\StockCondition;
use App\Enums\SupplierDebitNoteStatus;
use App\Enums\SupplierReturnExpectedOutcome;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryReturn;
use App\Models\InventoryStock;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\TaxRecognitionEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use App\Services\Inventory\InventoryReturnService;
use App\Services\Purchasing\SupplierDebitNoteService;
use Database\Seeders\AccountingPermissionSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * WP-4.8 (PHASE_4_PLAN.md §2, ADR 0015): a supplier debit note derived
 * strictly from the return-line -> receipt-line -> PO-line -> bill-line
 * chain, and the posting that reduces the payable and reverses input tax.
 */
function debitNoteActor(): User
{
    (new AccountingPermissionSeeder)->run();
    (new InventoryPermissionSeeder)->run();

    $user = User::factory()->admin()->create();
    $user->givePermissionTo([
        AccountingPermission::SupplierDebitNoteManage->value,
        AccountingPermission::SupplierDebitNoteConfirm->value,
        AccountingPermission::SupplierDebitNoteReverse->value,
        InventoryPermission::ReturnCreate->value,
    ]);

    if (! FiscalPeriod::query()->exists()) {
        FiscalPeriod::factory()->create();
    }

    return $user;
}

/**
 * A posted supplier return, referencing a receipt line that in turn
 * references a purchase-order line also billed on $bill — the exact
 * provenance chain SupplierDebitNoteService::deriveLine() requires.
 *
 * @return array{0: InventoryReturn, 1: Bill, 2: User}
 */
function creditableSupplierReturn(
    string $orderedQuantity,
    string $returnedQuantity,
    string $unitCost,
    string $billedQuantity,
    string $taxAmount = '0.00',
): array {
    $actor = debitNoteActor();
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    $purchaseOrder = PurchaseOrder::factory()->sent()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
    ]);
    $purchaseOrderLine = $purchaseOrder->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => $orderedQuantity,
        'unit_cost' => $unitCost,
    ]);
    // line_total/conversion_factor_snapshot/base_quantity are deliberately not
    // mass-assignable (they're normally derived by PurchaseOrderService) —
    // force-filled here since this test builds the chain directly.
    $purchaseOrderLine->forceFill([
        'line_total' => bcmul($orderedQuantity, $unitCost, 2),
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => $orderedQuantity,
    ])->save();

    $receipt = InventoryOperation::factory()->receipt()->create([
        'destination_warehouse_id' => $warehouse->getKey(),
        'supplier_id' => $supplier->getKey(),
        'source_document_type' => PurchaseOrder::class,
        'source_document_id' => $purchaseOrder->getKey(),
    ]);
    $receiptLine = $receipt->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity' => $orderedQuantity,
        'unit_cost' => $unitCost,
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
    ]);

    app(InventoryOperationService::class)->markReady($receipt, $actor);
    app(InventoryOperationService::class)->complete($receipt->refresh(), $actor);
    $receiptLine = $receiptLine->refresh();
    $lotId = $receiptLine->inventory_lot_id;

    InventoryStock::query()
        ->where('product_variant_id', $variant->getKey())
        ->where('warehouse_id', $warehouse->getKey())
        ->firstOrFail()
        ->forceFill(['available_quantity' => $orderedQuantity])
        ->save();

    $bill = Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'status' => BillStatus::Draft,
        'subtotal' => bcmul($billedQuantity, $unitCost, 2),
        'tax_total' => $taxAmount,
        'total_amount' => bcadd(bcmul($billedQuantity, $unitCost, 2), $taxAmount, 2),
    ]);
    $bill->lines()->create([
        'purchase_order_line_id' => $purchaseOrderLine->getKey(),
        'product_variant_id' => $variant->getKey(),
        'chart_account_id' => ChartAccount::query()->where('code', '5100')->value('id'),
        'description' => 'PO-linked goods',
        'quantity' => $billedQuantity,
        'unit_price' => $unitCost,
        'tax_amount' => $taxAmount,
        'line_total' => bcmul($billedQuantity, $unitCost, 2),
        'sort_order' => 0,
    ]);
    // The BillLine observer refuses changes once the bill is approved, so the
    // bill is approved only after its line already exists.
    $bill->forceFill([
        'status' => BillStatus::Approved,
        'approved_by' => $actor->getKey(),
        'approved_at' => now(),
    ])->save();

    $returns = app(InventoryReturnService::class);
    $return = $returns->createSupplierReturn($actor, $supplier, $warehouse, $receipt);
    $returns->addSupplierLine(
        $return,
        $variant,
        (int) $variant->unit_id,
        $returnedQuantity,
        StockCondition::Saleable,
        $lotId,
        null,
        $receiptLine,
    );

    // The outcome can only be set while the return is still draft.
    app(SupplierDebitNoteService::class)->setExpectedOutcome(
        $actor,
        $return->refresh(),
        SupplierReturnExpectedOutcome::Credit,
    );

    $returns->markReady($return->refresh(), $actor);
    $posted = $returns->post($return->refresh(), $actor);

    return [$posted->refresh(), $bill->refresh(), $actor];
}

it('derives a debit note line from the return-to-receipt-to-PO-to-bill chain', function (): void {
    (new ChartOfAccountsSeeder)->run();
    PurchaseSetting::current()->forceFill([
        'grni_account_id' => ChartAccount::query()->where('code', '2100')->value('id'),
    ])->save();

    [$return, $bill, $actor] = creditableSupplierReturn(
        orderedQuantity: '10.000000',
        returnedQuantity: '2.000000',
        unitCost: '5.0000',
        billedQuantity: '10.000',
    );

    $note = app(SupplierDebitNoteService::class)->createForReturn($actor, $return, $bill);

    expect($note->status)->toBe(SupplierDebitNoteStatus::Draft)
        ->and($note->supplier_id)->toBe($return->supplier_id)
        ->and((string) $note->subtotal)->toBe('10.00')
        ->and((string) $note->total_amount)->toBe('10.00')
        ->and($note->lines)->toHaveCount(1);

    $line = $note->lines->first();
    expect((string) $line->quantity)->toBe('2.000000')
        ->and((string) $line->line_total)->toBe('10.00');
});

it('refuses a debit note for a return with no expected credit outcome', function (): void {
    (new ChartOfAccountsSeeder)->run();

    $actor = debitNoteActor();
    $warehouse = Warehouse::factory()->create();
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    InventoryStock::factory()->for($variant)->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'damaged_quantity' => '0.000000',
        'available_quantity' => '5.000000',
    ]);
    $lot = InventoryLot::factory()->for($variant, 'productVariant')->for($warehouse)->create([
        'on_hand_quantity' => '5.000000',
        'reserved_quantity' => '0.000000',
        'expires_at' => null,
    ]);

    $returns = app(InventoryReturnService::class);
    $return = $returns->createSupplierReturn($actor, $supplier, $warehouse);
    $returns->addSupplierLine($return, $variant, (int) $variant->unit_id, '1.000000', StockCondition::Saleable, (int) $lot->getKey());
    $returns->markReady($return, $actor);
    $posted = $returns->post($return->refresh(), $actor);
    // No setExpectedOutcome call — this return has no expected_outcome at all.

    $bill = Bill::factory()->create(['supplier_id' => $supplier->getKey(), 'status' => BillStatus::Approved]);

    expect(fn () => app(SupplierDebitNoteService::class)->createForReturn($actor, $posted, $bill))
        ->toThrow(DomainException::class, 'must expect a credit or refund');
});

it('confirms a debit note by reducing the bill payable and posting the reversing journal entry', function (): void {
    (new ChartOfAccountsSeeder)->run();
    $grniAccountId = ChartAccount::query()->where('code', '2100')->value('id');
    PurchaseSetting::current()->forceFill(['grni_account_id' => $grniAccountId])->save();

    [$return, $bill, $actor] = creditableSupplierReturn(
        orderedQuantity: '10.000000',
        returnedQuantity: '2.000000',
        unitCost: '5.0000',
        billedQuantity: '10.000',
        taxAmount: '1.00',
    );

    $service = app(SupplierDebitNoteService::class);
    $note = $service->createForReturn($actor, $return, $bill);
    $confirmed = $service->confirm($actor, $note);

    expect($confirmed->status)->toBe(SupplierDebitNoteStatus::Confirmed)
        ->and((string) $bill->refresh()->supplier_credit_total)->toBe((string) $confirmed->total_amount);

    $posting = JournalEntry::query()
        ->where('source_type', $confirmed->getMorphClass())
        ->where('source_id', $confirmed->getKey())
        ->with('lines')
        ->firstOrFail();

    $payableAccountId = ChartAccount::query()->where('code', '2100')->value('id');
    $inputTaxAccountId = ChartAccount::query()->where('code', '1450')->value('id');

    expect((float) $posting->lines->firstWhere('chart_account_id', $payableAccountId)->debit)
        ->toBe((float) $confirmed->total_amount);

    if ((float) $confirmed->tax_total > 0) {
        expect(TaxRecognitionEntry::query()->where('source_type', $confirmed->getMorphClass())->where('source_id', $confirmed->getKey())->exists())->toBeTrue()
            ->and($posting->lines->firstWhere('chart_account_id', $inputTaxAccountId))->not->toBeNull();
    }
});

it('refuses to confirm a debit note that would credit more than the original bill total', function (): void {
    (new ChartOfAccountsSeeder)->run();
    PurchaseSetting::current()->forceFill([
        'grni_account_id' => ChartAccount::query()->where('code', '2100')->value('id'),
    ])->save();

    [$return, $bill, $actor] = creditableSupplierReturn(
        orderedQuantity: '10.000000',
        returnedQuantity: '10.000000',
        unitCost: '50.0000',
        billedQuantity: '1.000',
    );

    $service = app(SupplierDebitNoteService::class);

    expect(fn () => $service->createForReturn($actor, $return, $bill))
        ->toThrow(DomainException::class, 'exceeds the selected bill-line quantity');
});

it('reverses a confirmed debit note, restoring the bill payable and reversing the journal entry', function (): void {
    (new ChartOfAccountsSeeder)->run();
    PurchaseSetting::current()->forceFill([
        'grni_account_id' => ChartAccount::query()->where('code', '2100')->value('id'),
    ])->save();

    [$return, $bill, $actor] = creditableSupplierReturn(
        orderedQuantity: '10.000000',
        returnedQuantity: '2.000000',
        unitCost: '5.0000',
        billedQuantity: '10.000',
    );

    $service = app(SupplierDebitNoteService::class);
    $note = $service->createForReturn($actor, $return, $bill);
    $confirmed = $service->confirm($actor, $note);

    $reversed = $service->reverse($actor, $confirmed);

    expect($reversed->status)->toBe(SupplierDebitNoteStatus::Reversed)
        ->and((string) $bill->refresh()->supplier_credit_total)->toBe('0.00');

    expect(JournalEntry::query()
        ->where('description', 'like', 'Reverse supplier debit note%')
        ->exists())->toBeTrue();
});

it('reports supplier returns awaiting a credit that never arrived', function (): void {
    (new ChartOfAccountsSeeder)->run();

    [$return] = creditableSupplierReturn(
        orderedQuantity: '10.000000',
        returnedQuantity: '2.000000',
        unitCost: '5.0000',
        billedQuantity: '10.000',
    );

    $awaiting = app(SupplierDebitNoteService::class)->awaitingSupplierCreditQuery()->get();

    expect($awaiting->pluck('id'))->toContain($return->getKey());
});
