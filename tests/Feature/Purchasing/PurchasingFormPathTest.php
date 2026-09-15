<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchasingReports\Pages\ListPurchasingReports;
use App\Filament\Resources\PurchasingReports\PurchasingReportResource;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Filament\Resources\SupplierProductReferences\Pages\ManageSupplierProductReferences;
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
    $order->supplier()->update(['requires_confirmation' => true]);
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->getKey(),
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 2,
        'unit_cost' => '10.00',
    ]);

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('create'), [
            'purchase_order_id' => $order->getKey(),
            'notes' => 'Asked by email',
        ]);

    $confirmation = SupplierConfirmation::query()->sole();

    expect($confirmation->purchase_order_id)->toBe($order->getKey())
        ->and($confirmation->supplier_id)->toBe($order->supplier_id)
        ->and($confirmation->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($confirmation->notes)->toBe('Asked by email')
        ->and($confirmation->items)->toHaveCount(1);
});

it('offers the purchase order field once the create action is opened', function (): void {
    Livewire::test(ManageSupplierConfirmations::class)
        ->mountAction(TestAction::make('create'))
        ->assertSchemaStateSet([])
        ->assertFormFieldExists('purchase_order_id')
        ->assertFormFieldExists('notes');
});

it('answers a confirmation with a rejection through the page action', function (): void {
    $order = PurchaseOrder::factory()->sent()->create();
    $confirmation = SupplierConfirmation::factory()->create([
        'purchase_order_id' => $order->getKey(),
        'supplier_id' => $order->supplier_id,
    ]);
    $confirmation->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'requested_quantity' => 1,
    ]);

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('supplierResponse')->table($confirmation), [
            'response' => SupplierConfirmationStatus::Rejected->value,
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
            'supplier_name' => 'Acme',
            'supplier_item_number' => 'ACME-1',
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
