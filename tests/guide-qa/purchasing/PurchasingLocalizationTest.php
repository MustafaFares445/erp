<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Schemas\PurchaseOrderInfolist;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// The QA snapshot shares a Composer vendor junction with the main workspace.
// Explicitly load ONLY this agent's modified Purchasing classes to ensure this
// regression tests worker-03 code rather than Composer's main-worktree classmap.
require_once __DIR__.'/../../../app/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderForm.php';
require_once __DIR__.'/../../../app/Filament/Resources/PurchaseOrders/Schemas/PurchaseOrderInfolist.php';
require_once __DIR__.'/../../../app/Filament/Resources/PurchaseOrders/Pages/ViewPurchaseOrder.php';

uses(TestCase::class, RefreshDatabase::class);

it('localizes workflow projection display copy in Arabic without changing the English source values', function (): void {
    app()->setLocale('en');
    expect(PurchaseOrderInfolist::display('Procurement complete'))->toBe('Procurement complete')
        ->and(PurchaseOrderInfolist::display('Next action'))->toBe('Next action');
    app()->setLocale('ar');
    expect(PurchaseOrderInfolist::display('Procurement complete'))->toBe('اكتمل الشراء')
        ->and(PurchaseOrderInfolist::display('Next action'))->toBe('Next action')
        ->and(PurchaseOrderInfolist::display('No action is currently required for this Purchase Order.'))->toBe('لا يلزم أي إجراء حالي لأمر الشراء هذا.')
        ->and(PurchaseOrderInfolist::display('Supplier confirmation is not required for this Purchase Order.'))->toBe('لا يتطلب أمر الشراء هذا تأكيداً من المورد.');
});

it('displays an Arabic Purchase Order title and required stage without changing underlying workflow state', function (): void {
    (new PurchasePermissionSeeder)->run();
    $manager = User::factory()->admin()->create();
    $manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($manager);
    $po = PurchaseOrder::factory()->create();
    app()->setLocale('ar');
    Livewire::test(ViewPurchaseOrder::class, ['record' => $po->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('أمر الشراء '.$po->purchase_order_number)
        ->assertSee('المشتريات')
        ->assertSee('أكمل البيانات التجارية وقدّم الطلب')
        ->assertSee('ملخص أمر الشراء')
        ->assertSee('شروط الدفع')
        ->assertSee('تاريخ الطلب');

    expect($po->fresh()->status->value)->toBe('draft')
        ->and($po->fresh()->sent_at)->toBeNull();
});
