<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\Bills\Pages\EditBill;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\FiscalPeriods\Pages\ViewFiscalPeriod;
use App\Models\Bill;
use App\Models\BillLine;
use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccountingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows linked purchase order quantities and supplier context in Arabic on bill edit', function (): void {
    (new AccountingPermissionSeeder)->run();
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->assignRole(DashboardRole::ChiefAccountant->value);

    $order = PurchaseOrder::factory()->accepted()->create();
    $orderLine = PurchaseOrderLine::factory()->for($order, 'purchaseOrder')->create([
        'quantity_ordered' => '40.000000',
        'unit_id' => Unit::factory()->create(['name' => 'Each'])->getKey(),
    ]);
    $bill = Bill::factory()->forPurchaseOrder($order)->create();
    BillLine::factory()->for($bill, 'bill')->create(['purchase_order_line_id' => $orderLine->getKey()]);

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test(EditBill::class, ['record' => $bill->getKey()])
        ->assertSee('الكمية المطلوبة 40 وحدة')
        ->assertSeeText('مستمد من أمر الشراء المرتبط؛ لا يمكن تحديد مورد مستقل لهذه الفاتورة.')
        ->assertDontSeeText('ordered 40.000000');
});

it('shows the expense requester name and localized expense details', function (): void {
    (new AccountingPermissionSeeder)->run();
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->assignRole(DashboardRole::ChiefAccountant->value);

    $requester = EmployeeProfile::factory()->for(User::factory()->create(['name' => 'QA Requester']), 'user')->create();
    $expense = Expense::factory()->for($requester, 'requestedBy')->create();

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test(ViewExpense::class, ['record' => $expense->getKey()])
        ->assertSeeText('QA Requester')
        ->assertSeeText('تفاصيل المصروف')
        ->assertSeeText('حساب المصروف')
        ->assertSeeText('غير مدفوع');
});

it('shows fiscal period state under a status label in Arabic', function (bool $closed, string $state): void {
    (new AccountingPermissionSeeder)->run();
    $admin = User::factory()->admin()->create(['locale' => 'ar']);
    $admin->assignRole(DashboardRole::ChiefAccountant->value);

    $period = FiscalPeriod::factory()->create(['is_closed' => $closed]);

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test(ViewFiscalPeriod::class, ['record' => $period->getKey()])
        ->assertSeeText($state)
        ->assertSeeText('الحالة')
        ->assertDontSeeText('(advisory)');
})->with([
    'open' => [false, 'مفتوح'],
    'closed' => [true, 'مغلق'],
]);
