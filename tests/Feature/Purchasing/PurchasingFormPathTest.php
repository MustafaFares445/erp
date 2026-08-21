<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchasingReports\Pages\ListPurchasingReports;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Filament\Resources\SupplierConfirmations\SupplierConfirmationResource;
use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierConfirmation;
use App\Models\SupplierProductReference;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * The write paths that only exist inside a Filament page.
 *
 * A `->using()` closure and a dependent `->options()` callback are code that no
 * service test can reach: they run only when the page mounts and the form is
 * filled. Left untested they are exactly where a resource ends up writing a row
 * directly instead of going through the service that enforces the rules.
 */

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();

    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

it('records a confirmation against a purchase order through the page', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('create'), [
            'confirmable_type' => PurchaseOrder::class,
            'confirmable_id' => $order->getKey(),
            'supplier_id' => $order->supplier_id,
            'notes' => 'Asked by email',
        ]);

    $confirmation = SupplierConfirmation::query()->sole();

    expect($confirmation->confirmable_type)->toBe(PurchaseOrder::class)
        ->and($confirmation->confirmable_id)->toBe($order->getKey())
        ->and($confirmation->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($confirmation->notes)->toBe('Asked by email');
});

it('records a confirmation against a customer order through the page', function (): void {
    $customerOrder = Order::factory()->create();
    $supplier = Supplier::factory()->create();

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('create'), [
            'confirmable_type' => Order::class,
            'confirmable_id' => $customerOrder->getKey(),
            'supplier_id' => $supplier->getKey(),
        ]);

    expect(SupplierConfirmation::query()->sole()->confirmable_type)->toBe(Order::class)
        // The service reacted to the customer order, which is the whole reason
        // the page resolves a model rather than passing a type string through.
        ->and($customerOrder->refresh()->status)->toBe('pending_supplier_confirmation');
});

it('offers the right documents once a target type is chosen', function (): void {
    $purchaseOrder = PurchaseOrder::factory()->create();
    $customerOrder = Order::factory()->create();

    Livewire::test(ManageSupplierConfirmations::class)
        ->mountAction(TestAction::make('create'))
        ->assertSchemaStateSet([])
        ->fillForm(['confirmable_type' => PurchaseOrder::class])
        ->assertFormFieldExists('confirmable_id');

    // Asserted directly as well, because the dependent options callback is what
    // decides whether a buyer can even see the document they mean to attach to.
    $reflection = new ReflectionMethod(SupplierConfirmationResource::class, 'targetOptions');

    expect($reflection->invoke(null, PurchaseOrder::class))
        ->toBe([$purchaseOrder->getKey() => $purchaseOrder->purchase_order_number])
        ->and($reflection->invoke(null, Order::class))
        ->toBe([$customerOrder->getKey() => $customerOrder->order_number])
        // Anything else offers nothing, so an unsupported morph cannot be picked
        // in the first place (V-09).
        ->and($reflection->invoke(null, Supplier::class))->toBe([]);
});

it('rejects a confirmation through the page action', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'confirmable_type' => PurchaseOrder::class,
        'confirmable_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('rejectConfirmation')->table($confirmation), [
            'notes' => 'Discontinued line',
        ]);

    expect($confirmation->refresh()->confirmation_status)->toBe(SupplierConfirmationStatus::Rejected)
        ->and($confirmation->notes)->toBe('Discontinued line');
});

it('creates and edits a supplier product reference through the page', function (): void {
    $supplier = Supplier::factory()->create();
    $variant = ProductVariant::factory()->create();

    Livewire::test(ManageSupplierProductReferences::class)
        ->callAction(TestAction::make('create'), [
            'supplier_id' => $supplier->getKey(),
            'product_variant_id' => $variant->getKey(),
            'supplier_item_number' => 'ACME-1',
            'manufacturer' => 'Acme',
            'purchase_cost' => 12.5,
            'currency_code' => 'AED',
            'is_active' => true,
            'notes' => 'Preferred source',
        ]);

    $reference = SupplierProductReference::query()->sole();

    expect($reference->purchase_cost)->toBe('12.50')
        ->and($reference->supplier_item_number)->toBe('ACME-1');

    Livewire::test(ManageSupplierProductReferences::class)
        ->callAction(TestAction::make('edit')->table($reference), [
            'supplier_id' => $supplier->getKey(),
            'product_variant_id' => $variant->getKey(),
            'supplier_item_number' => 'ACME-2',
            'purchase_cost' => 13,
            'currency_code' => 'AED',
        ]);

    expect($reference->refresh()->supplier_item_number)->toBe('ACME-2')
        ->and($reference->purchase_cost)->toBe('13.00');
});

it('exposes the report resource as read-only, with no form of its own', function (): void {
    // The resource exists because Filament requires one behind a page; it is not
    // a CRUD surface, and these are the assertions that keep it from becoming one.
    expect(PurchasingReportResource::canViewAny())->toBeTrue()
        ->and(PurchasingReportResource::canCreate())->toBeFalse()
        ->and(PurchasingReportResource::getNavigationLabel())->toBe(__('admin.resources.purchasing_reports'));

    $schema = PurchasingReportResource::form(Schema::make(
        Livewire::test(ListPurchasingReports::class)->instance(),
    ));

    expect($schema->getComponents())->toBe([]);
});
