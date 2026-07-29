<?php

declare(strict_types=1);

use App\Enums\InventoryPermission;
use App\Enums\OperationStage;
use App\Enums\OperationType;
use App\Enums\ReceiptStatus;
use App\Enums\ReservationStatus;
use App\Models\Brand;
use App\Models\InventoryOperation;
use App\Models\InventoryReceipt;
use App\Models\InventoryStock;
use App\Models\Package;
use App\Models\PackageType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\CatalogPolicy;
use App\Policies\CustomerPricingTierPolicy;
use App\Policies\CustomerProfilePolicy;
use App\Policies\InventoryAlertPolicy;
use App\Policies\InventoryExportPolicy;
use App\Policies\InventoryLotPolicy;
use App\Policies\InventoryOperationPolicy;
use App\Policies\InventoryReceiptPolicy;
use App\Policies\InventorySettingPolicy;
use App\Policies\PackagePolicy;
use App\Policies\PackageTypePolicy;
use App\Policies\PriceFloorOverridePolicy;
use App\Policies\PriceHistoryPolicy;
use App\Policies\PricingTierPolicy;
use App\Policies\SerializedInventoryUnitPolicy;
use App\Policies\StockReservationPolicy;
use App\Policies\StockTransferPolicy;
use Database\Seeders\InventoryPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    (new InventoryPermissionSeeder)->run();
});

it('enforces the catalog reference and management matrix', function (): void {
    $manager = fullyAuthorizedInventoryUser();
    $policy = new CatalogPolicy;
    $product = Product::factory()->create();
    $variant = ProductVariant::factory()->for($product)->create();
    $unit = Unit::factory()->create([
        'name' => 'Each',
        'symbol' => 'EA',
        'allows_decimal' => false,
        'is_active' => true,
    ]);
    $supplier = Supplier::factory()->create([
        'name' => 'Policy Supplier',
        'code' => 'POLICY-SUP',
        'is_active' => true,
    ]);

    expect($policy->viewAny($manager))->toBeTrue()
        ->and($policy->view($manager))->toBeTrue()
        ->and($policy->create($manager))->toBeTrue()
        ->and($policy->update($manager))->toBeTrue()
        ->and($policy->restore($manager))->toBeTrue()
        ->and($policy->forceDelete())->toBeFalse()
        ->and($policy->delete($manager, Brand::query()->create([
            'name' => 'Policy Brand',
            'code' => 'POLICY-BRAND',
            'is_active' => true,
        ])))->toBeTrue()
        ->and($policy->delete($manager, $product))->toBeFalse();

    $unreferencedVariant = ProductVariant::factory()->create();

    expect($policy->delete($manager, $unreferencedVariant))->toBeTrue();

    InventoryStock::factory()->for($unreferencedVariant)->create();

    expect($policy->delete($manager, $unreferencedVariant))->toBeFalse()
        ->and($policy->delete($manager, $unit))->toBeTrue();

    ProductVariant::factory()->for($unit)->create();

    expect($policy->delete($manager, $unit))->toBeFalse()
        ->and($policy->delete($manager, $supplier))->toBeTrue();

    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'supplier_name' => $supplier->name,
        'supplier_item_number' => 'POLICY-REF',
        'currency_code' => 'USD',
        'is_active' => true,
    ]);

    expect($policy->delete($manager, $supplier))->toBeFalse();
});

it('keeps pricing histories immutable while authorizing managed pricing surfaces', function (): void {
    $manager = fullyAuthorizedInventoryUser();
    $tier = new PricingTierPolicy;
    $assignment = new CustomerPricingTierPolicy;
    $history = new PriceHistoryPolicy;
    $override = new PriceFloorOverridePolicy;

    expect($tier->viewAny($manager))->toBeTrue()
        ->and($tier->view($manager))->toBeTrue()
        ->and($tier->create($manager))->toBeTrue()
        ->and($tier->update($manager))->toBeTrue()
        ->and($tier->delete($manager))->toBeTrue()
        ->and($tier->restore($manager))->toBeTrue()
        ->and($tier->forceDelete())->toBeFalse()
        ->and($assignment->viewAny($manager))->toBeTrue()
        ->and($assignment->view($manager))->toBeTrue()
        ->and($assignment->create($manager))->toBeTrue()
        ->and($assignment->update())->toBeFalse()
        ->and($assignment->delete())->toBeFalse()
        ->and($assignment->restore())->toBeFalse()
        ->and($assignment->forceDelete())->toBeFalse()
        ->and($history->viewAny($manager))->toBeTrue()
        ->and($history->view($manager))->toBeTrue()
        ->and($history->create())->toBeFalse()
        ->and($history->update())->toBeFalse()
        ->and($history->delete())->toBeFalse()
        ->and($history->restore())->toBeFalse()
        ->and($history->forceDelete())->toBeFalse()
        ->and($override->viewAny($manager))->toBeTrue()
        ->and($override->view($manager))->toBeTrue()
        ->and($override->create())->toBeFalse()
        ->and($override->update())->toBeFalse()
        ->and($override->delete())->toBeFalse()
        ->and($override->restore())->toBeFalse()
        ->and($override->forceDelete())->toBeFalse();
});

it('authorizes read-only inventory sources and rejects their mutations', function (): void {
    $manager = fullyAuthorizedInventoryUser();

    foreach ([
        new InventoryAlertPolicy,
        new InventoryLotPolicy,
        new SerializedInventoryUnitPolicy,
    ] as $policy) {
        expect($policy->viewAny($manager))->toBeTrue()
            ->and($policy->view($manager))->toBeTrue()
            ->and($policy->create($manager))->toBeFalse()
            ->and($policy->update($manager))->toBeFalse()
            ->and($policy->delete($manager))->toBeFalse();
    }

    $export = new InventoryExportPolicy;
    $settings = new InventorySettingPolicy;

    expect($export->viewAny($manager))->toBeTrue()
        ->and($export->view($manager))->toBeTrue()
        ->and($export->create($manager))->toBeTrue()
        ->and($settings->viewAny($manager))->toBeTrue()
        ->and($settings->view($manager))->toBeTrue()
        ->and($settings->create($manager))->toBeTrue()
        ->and($settings->update($manager))->toBeTrue();
});

it('allows receipt and reservation mutations only in valid workflow states', function (): void {
    $manager = fullyAuthorizedInventoryUser();
    $receiptPolicy = new InventoryReceiptPolicy;
    $draft = InventoryReceipt::factory()->create();
    $confirmed = InventoryReceipt::factory()->create(['status' => ReceiptStatus::Confirmed]);

    expect($receiptPolicy->viewAny($manager))->toBeTrue()
        ->and($receiptPolicy->view($manager))->toBeTrue()
        ->and($receiptPolicy->create($manager))->toBeTrue()
        ->and($receiptPolicy->update($manager, $draft))->toBeTrue()
        ->and($receiptPolicy->delete($manager, $draft))->toBeTrue()
        ->and($receiptPolicy->restore($manager, $draft))->toBeTrue()
        ->and($receiptPolicy->confirm($manager, $draft))->toBeTrue()
        ->and($receiptPolicy->update($manager, $confirmed))->toBeFalse()
        ->and($receiptPolicy->delete($manager, $confirmed))->toBeFalse()
        ->and($receiptPolicy->restore($manager, $confirmed))->toBeFalse()
        ->and($receiptPolicy->confirm($manager, $confirmed))->toBeFalse()
        ->and($receiptPolicy->forceDelete())->toBeFalse();

    $reservationPolicy = new StockReservationPolicy;
    $active = StockReservation::query()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'warehouse_id' => Warehouse::factory()->create()->getKey(),
        'quantity' => 1,
        'source_type' => 'order',
        'source_id' => 1,
        'status' => ReservationStatus::Active,
    ]);
    $released = $active->replicate()->forceFill(['status' => ReservationStatus::Released]);

    expect($reservationPolicy->viewAny($manager))->toBeTrue()
        ->and($reservationPolicy->view($manager))->toBeTrue()
        ->and($reservationPolicy->release($manager, $active))->toBeTrue()
        ->and($reservationPolicy->release($manager, $released))->toBeFalse();
});

it('separates transfer dispatch and receipt authorization by workflow state', function (): void {
    $manager = fullyAuthorizedInventoryUser();
    $policy = new StockTransferPolicy;
    $draft = StockTransfer::factory()->create();
    $dispatched = StockTransfer::factory()->dispatched()->create();
    $received = StockTransfer::factory()->received()->create();
    $unauthorized = User::factory()->create();

    expect($policy->update($unauthorized, $draft))->toBeFalse()
        ->and($policy->delete($unauthorized, $draft))->toBeFalse()
        ->and($policy->confirm($unauthorized, $draft))->toBeFalse()
        ->and($policy->receive($unauthorized, $dispatched))->toBeFalse()
        ->and($policy->update($manager, $draft))->toBeTrue()
        ->and($policy->delete($manager, $draft))->toBeTrue()
        ->and($policy->confirm($manager, $draft))->toBeTrue()
        ->and($policy->confirm($manager, $received))->toBeFalse()
        ->and($policy->receive($manager, $dispatched))->toBeTrue()
        ->and($policy->receive($manager, $received))->toBeFalse();
});

it('maps every inventory operation policy ability to its operation type permission', function (): void {
    $policy = new InventoryOperationPolicy;

    foreach ([
        [OperationType::Receipt, InventoryPermission::ReceiptView, InventoryPermission::ReceiptCreate, InventoryPermission::ReceiptConfirm],
        [OperationType::Delivery, InventoryPermission::DeliveryView, InventoryPermission::DeliveryCreate, InventoryPermission::DeliveryConfirm],
        [OperationType::InternalTransfer, InventoryPermission::TransferView, InventoryPermission::TransferCreate, InventoryPermission::TransferConfirm],
    ] as [$type, $view, $create, $confirm]) {
        $user = User::factory()->admin()->create();
        $user->givePermissionTo([$view->value, $create->value, $confirm->value]);
        $operation = InventoryOperation::factory()->{$type === OperationType::Receipt ? 'receipt' : ($type === OperationType::Delivery ? 'delivery' : 'internalTransfer')}()->create([
            'stage' => OperationStage::Draft,
        ]);

        expect($policy->view($user, $operation))->toBeTrue()
            ->and($policy->createType($user, $type))->toBeTrue()
            ->and($policy->markReady($user, $operation))->toBeTrue()
            ->and($policy->complete($user, $operation->forceFill([
                'stage' => $type === OperationType::InternalTransfer ? OperationStage::InTransit : OperationStage::Ready,
            ])))->toBeTrue();
    }
});

it('authorizes operation create fallbacks, cancellation, restore, and rejects bulk destructive abilities', function (): void {
    $policy = new InventoryOperationPolicy;
    $user = User::factory()->admin()->create();
    $user->givePermissionTo([
        InventoryPermission::TransferView->value,
        InventoryPermission::TransferCreate->value,
    ]);
    $operation = InventoryOperation::factory()->internalTransfer()->draft()->create();

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->create($user))->toBeTrue()
        ->and($policy->restore($user))->toBeTrue()
        ->and($policy->update($user, $operation))->toBeTrue()
        ->and($policy->delete($user, $operation))->toBeTrue()
        ->and($policy->forceDelete())->toBeFalse()
        ->and($policy->deleteAny())->toBeFalse()
        ->and($policy->forceDeleteAny())->toBeFalse()
        ->and($policy->restoreAny())->toBeFalse();

    $user->givePermissionTo(InventoryPermission::TransferConfirm->value);
    $ready = InventoryOperation::factory()->internalTransfer()->ready()->create();

    expect($policy->dispatch($user, $ready))->toBeTrue()
        ->and($policy->cancel($user, $ready))->toBeTrue()
        ->and($policy->cancel($user, InventoryOperation::factory()->internalTransfer()->done()->create()))->toBeFalse();
});

it('authorizes receipt and delivery operation creation fallbacks', function (): void {
    $policy = new InventoryOperationPolicy;
    $receiptUser = User::factory()->admin()->create();
    $deliveryUser = User::factory()->admin()->create();
    $receiptUser->givePermissionTo(InventoryPermission::ReceiptCreate->value);
    $deliveryUser->givePermissionTo(InventoryPermission::DeliveryCreate->value);

    expect($policy->create($receiptUser))->toBeTrue()
        ->and($policy->create($deliveryUser))->toBeTrue();
});

it('authorizes package and package type management only for unreferenced records', function (): void {
    $manager = fullyAuthorizedInventoryUser();
    $packagePolicy = new PackagePolicy;
    $packageTypePolicy = new PackageTypePolicy;
    $packageType = PackageType::factory()->create();
    $package = Package::factory()->create();

    expect($packagePolicy->viewAny($manager))->toBeTrue()
        ->and($packagePolicy->view($manager))->toBeTrue()
        ->and($packagePolicy->create($manager))->toBeTrue()
        ->and($packagePolicy->update($manager))->toBeTrue()
        ->and($packagePolicy->delete($manager, $package))->toBeTrue()
        ->and($packagePolicy->restore($manager))->toBeTrue()
        ->and($packagePolicy->forceDelete())->toBeFalse()
        ->and($packagePolicy->deleteAny())->toBeFalse()
        ->and($packagePolicy->forceDeleteAny())->toBeFalse()
        ->and($packagePolicy->restoreAny())->toBeFalse()
        ->and($packageTypePolicy->viewAny($manager))->toBeTrue()
        ->and($packageTypePolicy->view($manager))->toBeTrue()
        ->and($packageTypePolicy->create($manager))->toBeTrue()
        ->and($packageTypePolicy->update($manager))->toBeTrue()
        ->and($packageTypePolicy->delete($manager, $packageType))->toBeTrue()
        ->and($packageTypePolicy->restore($manager))->toBeTrue()
        ->and($packageTypePolicy->forceDelete())->toBeFalse()
        ->and($packageTypePolicy->deleteAny())->toBeFalse()
        ->and($packageTypePolicy->forceDeleteAny())->toBeFalse()
        ->and($packageTypePolicy->restoreAny())->toBeFalse();

    $operation = InventoryOperation::factory()->receipt()->create();
    $operation->lines()->create([
        'product_variant_id' => ProductVariant::factory()->create()->getKey(),
        'quantity' => 1,
        'unit_id' => Unit::factory()->create()->getKey(),
        'package_id' => $package->getKey(),
    ]);

    Package::factory()->for($packageType, 'packageType')->create();

    expect($packagePolicy->delete($manager, $package->fresh()))->toBeFalse()
        ->and($packageTypePolicy->delete($manager, $packageType->fresh()))->toBeFalse();
});

it('allows customer profile administration only to administrators', function (): void {
    $policy = new CustomerProfilePolicy;
    $admin = User::factory()->admin()->create();
    $customer = User::factory()->customer()->create();

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->view($admin))->toBeTrue()
        ->and($policy->create($admin))->toBeTrue()
        ->and($policy->update($admin))->toBeTrue()
        ->and($policy->delete($admin))->toBeTrue()
        ->and($policy->restore($admin))->toBeTrue()
        ->and($policy->forceDelete())->toBeFalse()
        ->and($policy->viewAny($customer))->toBeFalse()
        ->and($policy->restore($customer))->toBeFalse();
});

function fullyAuthorizedInventoryUser(): User
{
    $manager = User::factory()->admin()->create();
    $manager->givePermissionTo(InventoryPermission::values());

    return $manager;
}
