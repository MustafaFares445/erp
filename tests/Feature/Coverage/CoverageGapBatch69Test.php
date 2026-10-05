<?php

declare(strict_types=1);

use App\Enums\BillStatus;
use App\Enums\ExpenseStatus;
use App\Enums\SupplierPaymentStatus;
use App\Filament\Resources\AccountsPayable\Pages\ListAccountsPayable;
use App\Models\Bill;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

function coverage69Page(): ListAccountsPayable
{
    return new ReflectionClass(ListAccountsPayable::class)->newInstanceWithoutConstructor();
}

it('builds payable document drill-down URLs for bills and expenses', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $page = coverage69Page();
    $bill = Bill::factory()->create();
    $expense = Expense::factory()->create();

    expect($page->documentUrl('bill', $bill->id))->toContain('/bills/'.$bill->id)
        ->and($page->documentUrl('expense', $expense->id))->toContain('/expenses/'.$expense->id)
        ->and($page->documentUrl('unknown', 1))->toBeNull();
});

it('guards supplier statement download when the selected supplier no longer exists', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $page = coverage69Page();
    $page->asOf = '2026-08-31';
    $page->supplierId = 999999;

    expect(fn (): StreamedResponse => $page->downloadStatement())
        ->toThrow(LogicException::class, 'selected supplier no longer exists');
});

it('streams a populated supplier statement including charges and allocated payments', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $supplier = Supplier::factory()->create(['name' => 'Coverage AP Supplier']);

    $bill = Bill::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => BillStatus::Approved,
        'bill_date' => '2026-08-01',
        'due_date' => '2026-08-20',
        'supplier_reference' => 'C69-BILL',
        'subtotal' => '100.00',
        'tax_total' => '0.00',
        'total_amount' => '100.00',
    ]);
    $expense = Expense::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => ExpenseStatus::Approved,
        'expense_date' => '2026-08-02',
        'due_date' => '2026-08-21',
        'subtotal' => '25.00',
        'tax_total' => '0.00',
        'total_amount' => '25.00',
    ]);
    $payment = SupplierPayment::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => SupplierPaymentStatus::Paid,
        'amount' => '40.00',
        'payment_date' => '2026-08-15',
        'reference' => 'C69-PAYMENT',
    ]);
    SupplierPaymentAllocation::factory()->create([
        'supplier_payment_id' => $payment->id,
        'bill_id' => $bill->id,
        'amount' => '40.00',
    ]);

    $page = coverage69Page();
    $page->asOf = '2026-08-31';
    $page->supplierId = $supplier->id;

    $response = $page->downloadStatement();
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('content-type'))->toContain('text/csv')
        ->and($csv)->toBeString()
        ->toContain('Coverage AP Supplier')
        ->toContain((string) $bill->bill_number)
        ->toContain((string) $expense->expense_number)
        ->toContain((string) $payment->supplier_payment_number)
        ->toContain('Carried forward');
});
