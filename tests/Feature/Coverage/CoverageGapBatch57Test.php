<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Filament\Resources\Bills\BillResource;
use App\Filament\Resources\Bills\Pages\ManageBills;
use App\Filament\Resources\Bills\Schemas\BillInfolist;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\SupplierPayments\SupplierPaymentResource;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\Expense;
use App\Models\PurchaseOrderLine;
use App\Models\Refund;
use App\Models\SupplierPayment;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function coverage57Action(string $class, string $method): Action
{
    $reflection = new ReflectionMethod($class, $method);
    $action = $reflection->invoke(null);

    expect($action)->toBeInstanceOf(Action::class);

    return $action;
}

it('covers bill action authentication guards', function (): void {
    auth()->logout();

    $bill = Bill::factory()->create(['status' => BillStatus::Draft->value]);

    foreach ([BillResource::approveAction(), BillResource::cancelAction()] as $action) {
        expect(fn (): mixed => ($action->getActionFunction())($bill))
            ->toThrow(LogicException::class, 'authenticated accounting user');
    }
});

it('covers expense action authentication guards', function (): void {
    auth()->logout();

    $expense = Expense::factory()->create();

    foreach (['approveAction', 'cancelAction'] as $method) {
        $action = coverage57Action(ExpenseResource::class, $method);

        expect(fn (): mixed => ($action->getActionFunction())($expense))
            ->toThrow(LogicException::class, 'authenticated accounting user');
    }

    $pay = coverage57Action(ExpenseResource::class, 'payAction');

    expect(fn (): mixed => ($pay->getActionFunction())($expense, ['payment_date' => today()->toDateString()]))
        ->toThrow(LogicException::class, 'authenticated accounting user');
});

it('covers refund action authentication guards', function (): void {
    auth()->logout();

    $refund = Refund::factory()->create();

    foreach (['payAction', 'cancelAction', 'approveAction'] as $method) {
        $action = coverage57Action(RefundResource::class, $method);

        expect(fn (): mixed => ($action->getActionFunction())($refund))
            ->toThrow(LogicException::class, 'authenticated accounting user');
    }
});

it('returns bill-create defaults when a requested purchase order does not exist', function (): void {
    $method = new ReflectionMethod(ManageBills::class, 'createDefaults');

    $defaults = $method->invoke(null, ['purchase_order_id' => 999999999]);

    expect($defaults)->toBe([
        'bill_date' => today()->toDateString(),
    ]);
});

it('covers bill infolist provisional-reference and variance blockers', function (): void {
    $method = new ReflectionMethod(BillInfolist::class, 'blocker');

    $provisional = new Bill;
    $provisional->forceFill([
        'status' => BillStatus::Draft,
        'supplier_reference' => 'PO-AUTO:PO-COV-057',
    ]);

    expect($method->invoke(null, $provisional))
        ->toBe('Replace the provisional reference with the supplier invoice reference');

    $purchaseLine = new PurchaseOrderLine;
    $purchaseLine->forceFill(['unit_cost' => '10.00']);

    $billLine = new BillLine;
    $billLine->forceFill([
        'purchase_order_line_id' => null,
        'unit_price' => '12.00',
    ]);
    $billLine->setRelation('purchaseOrderLine', $purchaseLine);

    $variance = new Bill;
    $variance->forceFill([
        'status' => BillStatus::Draft,
        'supplier_reference' => 'SUP-INV-057',
    ]);
    $variance->setRelation('lines', new Collection([$billLine]));

    expect($method->invoke(null, $variance))
        ->toBe('Three-way match variance requires review');
});

it('returns no default supplier-payment allocation for an unavailable bill', function (): void {
    request()->query->set('bill_id', '999999999');

    $payment = new SupplierPayment;
    $payment->forceFill([
        'supplier_id' => 123,
        'amount' => '50.00',
    ]);

    $method = new ReflectionMethod(SupplierPaymentResource::class, 'billAllocationDefaults');

    expect($method->invoke(null, $payment))->toBe([]);
});
