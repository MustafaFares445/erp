<?php

declare(strict_types=1);

use App\Enums\DashboardRole;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SupplierConfirmationStatus;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\SupplierConfirmations\Pages\ManageSupplierConfirmations;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SupplierConfirmation;
use App\Models\User;
use Database\Seeders\PurchasePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    (new PurchasePermissionSeeder)->run();
    $this->manager = User::factory()->admin()->create();
    $this->manager->assignRole(DashboardRole::PurchasingManager->value);
    $this->actingAs($this->manager);
});

it('searches Purchase Orders by reference and filters Ready to send without changing records', function (): void {
    $ready = PurchaseOrder::factory()->accepted()->create([
        'purchase_order_number' => 'PO-QA-READY-001',
        'sent_at' => null,
    ]);
    $sent = PurchaseOrder::factory()->sent()->create([
        'purchase_order_number' => 'PO-QA-SENT-002',
    ]);

    Livewire::test(ListPurchaseOrders::class)
        ->assertSuccessful()
        ->searchTable('PO-QA-READY-001')
        ->assertCanSeeTableRecords([$ready])
        ->assertCanNotSeeTableRecords([$sent])
        ->searchTable('')
        ->filterTable('ready_to_send')
        ->assertCanSeeTableRecords([$ready])
        ->assertCanNotSeeTableRecords([$sent]);

    expect($ready->fresh()->status)->toBe(PurchaseOrderStatus::Accepted)
        ->and($ready->fresh()->sent_at)->toBeNull()
        ->and($sent->fresh()->sent_at)->not->toBeNull();
});

it('creates pending supplier confirmation from the actual Purchasing Livewire action', function (): void {
    $order = PurchaseOrder::factory()->sent()->create([
        'supplier_confirmation_required' => true,
    ]);
    $order->supplier()->update(['requires_confirmation' => true]);
    $variant = ProductVariant::factory()->create();
    $order->lines()->create([
        'product_variant_id' => $variant->id,
        'unit_id' => $variant->unit_id,
        'quantity_ordered' => 2,
        'unit_cost' => '10.00',
    ]);

    Livewire::test(ManageSupplierConfirmations::class)
        ->assertSuccessful()
        ->mountAction(TestAction::make('create'))
        ->assertFormFieldExists('purchase_order_id')
        ->assertFormFieldExists('notes');

    Livewire::test(ManageSupplierConfirmations::class)
        ->callAction(TestAction::make('create'), [
            'purchase_order_id' => $order->id,
            'notes' => 'Supplier contacted by email',
        ]);

    $confirmation = SupplierConfirmation::query()->sole();
    expect($confirmation->purchase_order_id)->toBe($order->id)
        ->and($confirmation->supplier_id)->toBe($order->supplier_id)
        ->and($confirmation->confirmation_status)->toBe(SupplierConfirmationStatus::Pending)
        ->and($confirmation->items()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(PurchaseOrderStatus::Accepted);
});
