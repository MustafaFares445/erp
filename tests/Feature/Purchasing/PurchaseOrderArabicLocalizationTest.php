<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $manager = User::factory()->admin()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);

    $this->actingAs($manager);
});

dataset('purchase order workflow copy', [
    'Purchase Order' => ['Purchase Order', 'أمر الشراء'],
    'Expected' => ['Expected', 'المتوقع'],
    'Not specified' => ['Not specified', 'غير محدد'],
    'Created' => ['Created', 'إنشاء'],
    'Approval' => ['Approval', 'اعتماد'],
    'Sent' => ['Sent', 'إرسال'],
    'Supplier' => ['Supplier', 'المورد'],
    'Receiving' => ['Receiving', 'استلام'],
    'Accounting' => ['Accounting', 'المحاسبة'],
    'Complete' => ['Complete', 'مكتمل'],
    'Completed' => ['Completed', 'مكتمل'],
    'Cancelled' => ['Cancelled', 'ملغى'],
    'Closed' => ['Closed', 'إغلاق المتبقي'],
    'Short closed' => ['Short closed', 'إغلاق المتبقي'],
    'Purchasing' => ['Purchasing', 'المشتريات'],
    'Purchasing Manager' => ['Purchasing Manager', 'مدير المشتريات'],
    'Inventory' => ['Inventory', 'المخزون'],
    'None' => ['None', 'لا يوجد'],
    'Draft' => ['Draft', 'مسودة'],
    'Approval required' => ['Approval required', 'بانتظار الاعتماد'],
    'Accounting review' => ['Accounting review', 'مراجعة محاسبية'],
    'Accounting exception' => ['Accounting exception', 'مشكلة محاسبية'],
    'Payment pending' => ['Payment pending', 'بانتظار الدفع'],
    'Procurement complete' => ['Procurement complete', 'اكتمل الشراء'],
    'Ready to send' => ['Ready to send', 'جاهز للإرسال'],
    'Awaiting supplier confirmation' => ['Awaiting supplier confirmation', 'بانتظار تأكيد المورد'],
    'Supplier exception' => ['Supplier exception', 'مشكلة مع المورد'],
    'Supplier commitment unavailable' => ['Supplier commitment unavailable', 'لا يوجد التزام مورد مؤكد'],
    'Awaiting warehouse allocation' => ['Awaiting warehouse allocation', 'بانتظار التخصيص للمستودع'],
    'Receipt in progress' => ['Receipt in progress', 'الاستلام قيد التنفيذ'],
    'Backorder follow-up' => ['Backorder follow-up', 'متابعة الكمية المتأخرة'],
    'Accounting follow-up' => ['Accounting follow-up', 'متابعة محاسبية'],
    'Accepted' => ['Accepted', 'معتمد'],
    'No blocker' => ['No blocker', 'لا يوجد عائق'],
    'Returned for revision' => ['Returned for revision', 'أعيد للمراجعة'],
    'Waiting for approval' => ['Waiting for approval', 'بانتظار الموافقة'],
    'No Accounting Bill exists for this received Purchase Order' => ['No Accounting Bill exists for this received Purchase Order', 'لا توجد فاتورة مورد مرتبطة بأمر الشراء المستلم'],
    'Supplier Bill is still a draft' => ['Supplier Bill is still a draft', 'فاتورة المورد لا تزال مسودة'],
    'Supplier payable remains open' => ['Supplier payable remains open', 'ذمة المورد لا تزال غير مسددة'],
    'Purchase Order has not been sent to the supplier' => ['Purchase Order has not been sent to the supplier', 'لم يتم إرسال أمر الشراء إلى المورد'],
    'Supplier response required' => ['Supplier response required', 'يلزم تسجيل رد المورد'],
    'Supplier rejected requested quantity' => ['Supplier rejected requested quantity', 'رفض المورد جزءاً من الكمية المطلوبة'],
    'No confirmed quantity available' => ['No confirmed quantity available', 'لا توجد كمية مؤكدة من المورد'],
    'Confirmed quantity is not fully allocated' => ['Confirmed quantity is not fully allocated', 'الكمية المؤكدة لم تخصص بالكامل'],
    'Inventory receipt is still open' => ['Inventory receipt is still open', 'لا تزال عملية الاستلام المخزني مفتوحة'],
    'Confirmed quantity remains to be received' => ['Confirmed quantity remains to be received', 'تبقت كمية مؤكدة للاستلام'],
    'Supplier backorder remains' => ['Supplier backorder remains', 'لا تزال كمية متأخرة لدى المورد'],
    'Complete commercial details and submit' => ['Complete commercial details and submit', 'أكمل البيانات التجارية وقدّم الطلب'],
    'Approve or return for revision' => ['Approve or return for revision', 'اعتمد أو أعد للمراجعة'],
    'No further purchasing action' => ['No further purchasing action', 'لا توجد إجراءات شراء إضافية'],
    'Outstanding commitment was abandoned' => ['Outstanding commitment was abandoned', 'أُغلق الالتزام المتبقي'],
    'Create or reconcile the supplier bill' => ['Create or reconcile the supplier bill', 'أنشئ فاتورة المورد أو طابقها'],
    'Review and approve supplier bill' => ['Review and approve supplier bill', 'راجع فاتورة المورد واعتمدها'],
    'Settle the outstanding supplier payable' => ['Settle the outstanding supplier payable', 'سدّد ذمة المورد المتبقية'],
    'Send Purchase Order to supplier' => ['Send Purchase Order to supplier', 'أرسل أمر الشراء إلى المورد'],
    'Record supplier response' => ['Record supplier response', 'سجّل رد المورد'],
    'Resolve rejection or source another supplier' => ['Resolve rejection or source another supplier', 'عالج الرفض أو اختر مورداً آخر'],
    'Review supplier commitment' => ['Review supplier commitment', 'راجع التزام المورد'],
    'Allocate destination warehouse quantities' => ['Allocate destination warehouse quantities', 'وزّع الكميات على المستودعات المستهدفة'],
    'Complete the open receipt' => ['Complete the open receipt', 'أكمل عملية الاستلام المفتوحة'],
    'Receive allocated goods' => ['Receive allocated goods', 'استلم البضاعة المخصصة'],
    'Request the next supplier commitment' => ['Request the next supplier commitment', 'اطلب التزام المورد التالي'],
    'Review supplier bill and payment' => ['Review supplier bill and payment', 'راجع فاتورة المورد والدفعة'],
    'Review purchase order' => ['Review purchase order', 'راجع أمر الشراء'],
    'No action is currently required for this Purchase Order.' => ['No action is currently required for this Purchase Order.', 'لا يلزم أي إجراء حالي لأمر الشراء هذا.'],
    'What needs to happen next and who owns it.' => ['What needs to happen next and who owns it.', 'ما الإجراء التالي والجهة المسؤولة عنه.'],
    'Follow the Purchase Order from creation through supplier, receiving, accounting, and completion.' => ['Follow the Purchase Order from creation through supplier, receiving, accounting, and completion.', 'تابع أمر الشراء من إنشائه مروراً بالمورد والاستلام والمحاسبة حتى الإكمال.'],
    'Supplier promises, backorders, and delivery dates for this Purchase Order.' => ['Supplier promises, backorders, and delivery dates for this Purchase Order.', 'التزامات المورد والكميات المتأخرة ومواعيد التوريد لهذا الأمر.'],
    'Supplier confirmation is not required for this Purchase Order.' => ['Supplier confirmation is not required for this Purchase Order.', 'لا يتطلب أمر الشراء هذا تأكيداً من المورد.'],
    'Required' => ['Required', 'مطلوب'],
    'Not required' => ['Not required', 'غير مطلوب'],
]);

it('translates purchase order workflow copy to Arabic', function (string $english, string $arabic): void {
    app()->setLocale('ar');

    expect(PurchaseOrderInfolist::display($english))
        ->toBe($arabic)
        ->not->toBe($english);
})->with('purchase order workflow copy');

it('leaves purchase order workflow copy untouched in English', function (string $english): void {
    app()->setLocale('en');

    expect(PurchaseOrderInfolist::display($english))->toBe($english);
})->with([
    'status' => 'Procurement complete',
    'owner' => 'Purchasing Manager',
]);

it('passes unknown copy through and treats a missing value as empty', function (): void {
    app()->setLocale('ar');

    expect(PurchaseOrderInfolist::display('Free-form supplier remark'))->toBe('Free-form supplier remark')
        ->and(PurchaseOrderInfolist::display(null))->toBe('');
});

it('shows the Arabic workflow stepper and next-action guidance on a purchase order page', function (): void {
    app()->setLocale('ar');
    $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Draft]);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('إنشاء')
        ->assertSee('اعتماد')
        ->assertSee('مسودة')
        ->assertSee('أكمل البيانات التجارية وقدّم الطلب')
        ->assertSee('ما الإجراء التالي والجهة المسؤولة عنه.');
});

it('describes the purchase order form in Arabic when the admin locale is Arabic', function (): void {
    app()->setLocale('ar');

    Livewire::test(CreatePurchaseOrder::class)
        ->assertSuccessful()
        ->assertSee('أمر الشراء يوثق الالتزام التجاري مع المورد.');
});

it('describes the purchase order form in English by default', function (): void {
    app()->setLocale('en');

    Livewire::test(CreatePurchaseOrder::class)
        ->assertSuccessful()
        ->assertSee('Purchase Orders record supplier commercial commitments.');
});
