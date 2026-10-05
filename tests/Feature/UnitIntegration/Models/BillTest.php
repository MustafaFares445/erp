<?php

declare(strict_types=1);

use App\Exceptions\Domain\SupplierOwnershipConflict;
use App\Models\Bill;
use App\Models\ChartAccount;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a bill that sets both supplier_id and purchase_order_id', function (): void {
    $supplier = Supplier::factory()->create();
    $purchaseOrder = PurchaseOrder::factory()->accepted()->create();
    $expenseAccount = ChartAccount::factory()->create();

    expect(fn () => Bill::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'purchase_order_id' => $purchaseOrder->getKey(),
        'expense_account_id' => $expenseAccount->getKey(),
    ]))->toThrow(SupplierOwnershipConflict::class, 'must not also be set');
});

it('rejects a bill that sets neither supplier_id nor purchase_order_id', function (): void {
    $expenseAccount = ChartAccount::factory()->create();

    expect(fn () => Bill::factory()->create([
        'supplier_id' => null,
        'expense_account_id' => $expenseAccount->getKey(),
    ]))->toThrow(SupplierOwnershipConflict::class, 'requires supplier_id');
});

it('resolves a PO-linked bill supplier from the purchase order', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->accepted()->create();

    $bill = Bill::factory()->forPurchaseOrder($purchaseOrder)->create();

    expect($bill->supplier_id)->toBeNull()
        ->and($bill->resolved_supplier_id)->toBe($purchaseOrder->supplier_id)
        ->and($bill->resolvedSupplier->is($purchaseOrder->supplier))->toBeTrue()
        ->and($bill->supplier)->toBeNull();
});

it('resolves a standalone bill supplier from supplier_id', function (): void {
    $supplier = Supplier::factory()->create();

    $bill = Bill::factory()->create(['supplier_id' => $supplier->getKey()]);

    expect($bill->resolved_supplier_id)->toBe($supplier->getKey())
        ->and($bill->resolvedSupplier->is($supplier))->toBeTrue();
});
