<?php

declare(strict_types=1);

use App\Enums\ConditionChangeReason;
use App\Enums\InventoryConditionChangeStatus;
use App\Enums\InventoryConditionChangeType;
use App\Enums\InventoryReturnStatus;
use App\Enums\RefundStatus;
use App\Enums\StockCondition;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\InventoryConditionChange;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\ProductVariant;
use App\Models\PurchaseInboundAllocation;
use App\Models\PurchaseInboundLine;
use App\Models\Refund;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces issued invoice line immutability', function (): void {
    $invoice = Invoice::factory()->create(['issued_at' => now()]);
    $line = InvoiceLine::factory()->for($invoice, 'invoice')->create([
        'description' => 'Coverage line', 'quantity' => 1, 'unit_price' => 10,
        'tax_amount' => 0, 'line_total' => 10, 'sort_order' => 0,
    ]);

    $line->description = 'Changed';

    expect(fn () => $line->save())->toThrow(DomainException::class, 'immutable');
    $line->refresh();
    expect(fn () => $line->delete())->toThrow(DomainException::class, 'cannot be deleted');
});

it('enforces confirmed credit note line immutability', function (): void {
    $draft = CreditNote::factory()->create();
    $line = CreditNoteLine::factory()->for($draft, 'creditNote')->create();

    $draft->forceFill(['confirmed_at' => now()])->save();
    $line->description = 'Changed';
    expect(fn () => $line->save())->toThrow(DomainException::class, 'immutable');
    $line->refresh();
    expect(fn () => $line->delete())->toThrow(DomainException::class, 'cannot be deleted');

    $confirmed = CreditNote::factory()->create(['confirmed_at' => now()]);
    expect(fn () => CreditNoteLine::factory()->for($confirmed, 'creditNote')->create())
        ->toThrow(DomainException::class, 'cannot gain new lines');
});

it('covers purchase inbound quantity calculations and lower bound', function (): void {
    $detached = new PurchaseInboundLine;
    expect($detached->inboundBaseQuantity())->toBeNull()
        ->and($detached->unallocatedBaseQuantity())->toBeNull();

    $line = PurchaseInboundLine::factory()->create();
    $line->purchaseOrderLine()->update(['base_quantity' => '5.000000']);
    PurchaseInboundAllocation::factory()->for($line, 'purchaseInboundLine')->create([
        'allocated_base_quantity' => '7.000000',
    ]);

    expect($line->inboundBaseQuantity())->toBe('5.000000')
        ->and($line->allocatedBaseQuantity())->toBe('7.000000')
        ->and($line->unallocatedBaseQuantity())->toBe('0.000000');
});

it('enforces terminal inventory condition change immutability', function (): void {
    $actor = User::factory()->create();
    $variant = ProductVariant::factory()->machine()->create();
    $warehouse = Warehouse::factory()->create();
    $change = InventoryConditionChange::query()->create([
        'document_number' => 'ICC-LIFECYCLE-COV',
        'type' => InventoryConditionChangeType::Disposal,
        'status' => InventoryConditionChangeStatus::Draft,
        'product_variant_id' => $variant->getKey(),
        'warehouse_id' => $warehouse->getKey(),
        'condition_from' => StockCondition::Damaged,
        'condition_to' => StockCondition::Disposed,
        'base_quantity' => 1,
        'reason_category' => ConditionChangeReason::Other,
        'reason' => 'coverage',
        'created_by' => $actor->getKey(),
    ]);

    expect($change->isDraft())->toBeTrue();
    $change->forceFill(['status' => InventoryConditionChangeStatus::Posted])->save();
    expect($change->refresh()->isDraft())->toBeFalse();

    $change->reason = 'changed after posting';
    expect(fn () => $change->save())->toThrow(DomainException::class, 'immutable');
    $change->refresh();
    expect(fn () => $change->delete())->toThrow(DomainException::class, 'Only a draft');
});

it('enforces return line parent lifecycle guards', function (): void {
    $return = InventoryReturn::factory()->create();
    $line = InventoryReturnLine::factory()->for($return, 'inventoryReturn')->create();

    $return->forceFill(['status' => InventoryReturnStatus::Ready, 'ready_at' => now()])->save();
    $line->transaction_quantity = '2.000000';
    expect(fn () => $line->save())->toThrow(DomainException::class, 'frozen');

    $line->refresh();
    $return->forceFill(['status' => InventoryReturnStatus::Posted, 'posted_at' => now()])->save();
    $line->inspection_notes = 'cannot edit';
    expect(fn () => $line->save())->toThrow(DomainException::class, 'immutable');
    $line->refresh();
    expect(fn () => $line->delete())->toThrow(DomainException::class, 'draft');

    $orphan = new InventoryReturnLine([
        'inventory_return_id' => 999999,
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'transaction_quantity' => '1.000000',
        'transaction_unit_id' => 1,
        'conversion_factor_snapshot' => '1.000000',
        'base_quantity' => '1.000000',
    ]);
    expect(fn () => $orphan->save())->toThrow(DomainException::class, 'draft return');
});

it('enforces approved refund immutability and status helpers', function (): void {
    $refund = Refund::factory()->create();
    expect($refund->isDraft())->toBeTrue()
        ->and($refund->isApproved())->toBeFalse()
        ->and($refund->isPaid())->toBeFalse();

    $refund->forceFill([
        'status' => RefundStatus::Approved,
        'approved_at' => now(),
    ])->save();
    expect($refund->refresh()->isApproved())->toBeTrue();

    $refund->amount = '999.99';
    expect(fn () => $refund->save())->toThrow(DomainException::class, 'cannot be edited');
    $refund->refresh();
    expect(fn () => $refund->delete())->toThrow(DomainException::class, 'cannot be deleted');

    $paid = Refund::factory()->create(['status' => RefundStatus::Paid, 'paid_at' => now()]);
    expect($paid->isPaid())->toBeTrue();
});
