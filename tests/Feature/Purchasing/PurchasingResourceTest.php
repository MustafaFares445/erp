<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\RelationManagers\ConfirmationsRelationManager;
use App\Filament\Resources\PurchaseOrders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\PurchaseOrders\RelationManagers\ReceiptsRelationManager;
use App\Filament\Resources\PurchaseSettings\Pages\ManagePurchaseSettings;
use App\Filament\Resources\PurchasingReports\Pages\ListPurchasingReports;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseSetting;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Renders every purchasing surface through Livewire.
 *
 * This is what catches a broken schema, a missing translation key, or a column
 * pointing at a relation that does not exist — none of which a service test
 * would notice, because none of them is reachable without mounting the page.
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->admin = User::factory()->admin()->create();

    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);

    $this->officer = User::factory()->admin()->create();
    $this->officer->assignRole(DashboardRole::PurchasingOfficer->value);

    $this->actingAs($this->manager);
});

function seededOrder(PurchaseOrderStatus $status = PurchaseOrderStatus::Draft): PurchaseOrder
{
    $order = PurchaseOrder::factory()->create([
        'status' => $status,
        'destination_warehouse_id' => Warehouse::factory()->create()->getKey(),
    ]);

    $order->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'unit_id' => Unit::factory()->create()->getKey(),
        'quantity_ordered' => 5,
        'unit_cost' => '20.00',
        'line_total' => '100.00',
    ]);

    return $order->refresh();
}

it('renders the purchase order list with a record in every status', function (): void {
    foreach (PurchaseOrderStatus::cases() as $status) {
        seededOrder($status);
    }

    Livewire::test(ListPurchaseOrders::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(PurchaseOrder::query()->get());
});

it('renders the create form', function (): void {
    Livewire::test(CreatePurchaseOrder::class)->assertSuccessful();
});

it('creates a draft through the page, which routes through the service', function (): void {
    $supplier = Supplier::factory()->create();
    $warehouse = Warehouse::factory()->create();

    Livewire::test(CreatePurchaseOrder::class)
        ->fillForm([
            'supplier_id' => $supplier->getKey(),
            'destination_warehouse_id' => $warehouse->getKey(),
            'currency_code' => 'AED',
            'ordered_at' => today()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = PurchaseOrder::query()->sole();

    // The number proves the service ran: the form never submits one.
    expect($created->purchase_order_number)->toBe('PO-000001')
        ->and($created->status)->toBe(PurchaseOrderStatus::Draft);
});

it('renders the view page for an order in every status', function (): void {
    foreach (PurchaseOrderStatus::cases() as $status) {
        Livewire::test(ViewPurchaseOrder::class, ['record' => seededOrder($status)->getRouteKey()])
            ->assertSuccessful();
    }
});

it('renders the edit page for a draft', function (): void {
    Livewire::test(EditPurchaseOrder::class, ['record' => seededOrder()->getRouteKey()])
        ->assertSuccessful();
});

it('offers Submit on a draft and hides it once the order has left draft', function (): void {
    $draft = seededOrder();
    $sent = seededOrder(PurchaseOrderStatus::Sent);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $draft->getRouteKey()])
        ->assertActionVisible(TestAction::make('submit'));

    Livewire::test(ViewPurchaseOrder::class, ['record' => $sent->getRouteKey()])
        ->assertActionHidden(TestAction::make('submit'));
});

it('hides Approve, Send, Cancel, and Close from a purchasing officer', function (): void {
    $this->actingAs($this->officer);

    $pending = seededOrder(PurchaseOrderStatus::PendingApproval);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $pending->getRouteKey()])
        ->assertActionHidden(TestAction::make('approve'))
        ->assertActionHidden(TestAction::make('reject'));

    $approved = seededOrder(PurchaseOrderStatus::Approved);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $approved->getRouteKey()])
        ->assertActionHidden(TestAction::make('send'))
        ->assertActionHidden(TestAction::make('cancel'));
});

it('shows Approve to a purchasing manager on a pending order', function (): void {
    $pending = seededOrder(PurchaseOrderStatus::PendingApproval);

    Livewire::test(ViewPurchaseOrder::class, ['record' => $pending->getRouteKey()])
        ->assertActionVisible(TestAction::make('approve'))
        ->assertActionVisible(TestAction::make('reject'));
});

it('submits an order through the page action', function (): void {
    PurchaseSetting::factory()->create();
    $draft = seededOrder();

    Livewire::test(ViewPurchaseOrder::class, ['record' => $draft->getRouteKey()])
        ->callAction(TestAction::make('submit'));

    expect($draft->refresh()->status)->toBe(PurchaseOrderStatus::PendingApproval);
});

it('adds a line from the edit page through the service', function (): void {
    $order = PurchaseOrder::factory()->create();
    $unit = Unit::factory()->create();
    $variant = ProductVariant::factory()->create(['unit_id' => $unit->getKey()]);

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => EditPurchaseOrder::class,
    ])
        ->assertSuccessful()
        ->callAction(TestAction::make('create')->table(), [
            'product_variant_id' => $variant->getKey(),
            'unit_id' => $unit->getKey(),
            'quantity_ordered' => 3,
            'unit_cost' => 15,
        ]);

    // The stored document total proves the service ran rather than Filament's
    // own relationship save.
    expect($order->refresh()->total_amount)->toBe('45.00');
});

it('offers no line editing from the view page', function (): void {
    // Filament treats a relation manager on a ViewRecord page as read-only, and
    // that default is left in place: the view page is for reading an order, and
    // a draft is edited on the edit page.
    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => PurchaseOrder::factory()->create(),
        'pageClass' => ViewPurchaseOrder::class,
    ])
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('create')->table());
});

it('hides line editing on the edit page once the order has left draft', function (): void {
    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => seededOrder(PurchaseOrderStatus::Sent),
        'pageClass' => EditPurchaseOrder::class,
    ])
        ->assertSuccessful()
        ->assertActionHidden(TestAction::make('create')->table());
});

it('renders the receipts and confirmations relation managers', function (): void {
    $order = seededOrder(PurchaseOrderStatus::Sent);

    SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    $order->receipts()->create([
        'operation_type' => 'receipt',
        'destination_warehouse_id' => $order->destination_warehouse_id,
        'supplier_id' => $order->supplier_id,
    ])->lines()->create([
        'product_variant_id' => $order->lines()->firstOrFail()->product_variant_id,
        'unit_id' => $order->lines()->firstOrFail()->unit_id,
        'quantity' => 2,
        'unit_cost' => 22,
    ]);

    Livewire::test(ReceiptsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => ViewPurchaseOrder::class,
    ])->assertSuccessful();

    Livewire::test(ConfirmationsRelationManager::class, [
        'ownerRecord' => $order,
        'pageClass' => ViewPurchaseOrder::class,
    ])->assertSuccessful();
});

it('renders the supplier confirmations surface with both target types', function (): void {
    $order = PurchaseOrder::factory()->create();
    $customerOrder = Order::factory()->create();

    SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
    ]);

    SupplierConfirmation::factory()->confirmed()->create([
        'confirmable_type' => Order::class,
        'confirmable_id' => $customerOrder->getKey(),
    ]);

    Livewire::test(ManageSupplierConfirmations::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(SupplierConfirmation::query()->get());
});

it('answers a pending confirmation through the page action', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('confirm')->table($confirmation), [
            'promised_at' => $order->ordered_at->addWeek()->toDateString(),
        ]);

    expect($confirmation->refresh()->confirmation_status)->toBe(SupplierConfirmationStatus::Confirmed);
});

it('renders the supplier product reference surface', function (): void {
    SupplierProductReference::factory()->count(2)->create();

    Livewire::test(ManageSupplierProductReferences::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(SupplierProductReference::query()->get());
});

it('renders the settings surface for a System Admin and refuses a manager', function (): void {
    PurchaseSetting::factory()->create();

    $this->actingAs($this->admin);
    Livewire::test(ManagePurchaseSettings::class)->assertSuccessful();

    $this->actingAs($this->manager);
    Livewire::test(ManagePurchaseSettings::class)->assertForbidden();
});

it('renders the reports page with data in all three sections', function (): void {
    $order = seededOrder(PurchaseOrderStatus::Sent);
    $order->lines()->firstOrFail()->forceFill([
        'quantity_received' => 2,
        'last_received_unit_cost' => '22.00',
    ])->save();

    Livewire::test(ListPurchasingReports::class)->assertSuccessful();
});

it('refuses the reports page to a purchasing officer', function (): void {
    $this->actingAs($this->officer);

    Livewire::test(ListPurchasingReports::class)->assertForbidden();
});

it('shows the audit trail to a manager and withholds it from an officer', function (): void {
    $order = seededOrder(PurchaseOrderStatus::Sent);

    activity()
        ->performedOn($order)
        ->causedBy($this->manager)
        ->log('purchasing.order.sent');

    $page = Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()]);
    expect($page->instance()->auditTrail())->toHaveCount(1);

    $this->actingAs($this->officer);
    $officerPage = Livewire::test(ViewPurchaseOrder::class, ['record' => $order->getRouteKey()]);
    expect($officerPage->instance()->auditTrail())->toBe([]);
});
