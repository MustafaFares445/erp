<?php

declare(strict_types=1);

use App\Enums\MovementType;
use App\Enums\ShipmentStatus;
use App\Enums\WarrantyDurationUnit;
use App\Filament\Concerns\InteractsWithAccountingServices;
use App\Filament\Concerns\InteractsWithPurchasingServices;
use App\Filament\Concerns\InteractsWithSalesServices;
use App\Models\CustomerProfile;
use App\Models\InventoryMovement;
use App\Models\InventoryOperation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Services\Sales\DirectOrderLinePricingService;
use App\Services\Sales\SalesProcurementRequirementService;
use App\Services\Shipments\ShipmentService;
use App\Services\Support\WarrantyActivationService;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

final class AccountingConcernCoverageHarness
{
    use InteractsWithAccountingServices;

    public static function run(callable $operation): mixed
    {
        return self::runAccountingOperation($operation);
    }
}

final class PurchasingConcernCoverageHarness
{
    use InteractsWithPurchasingServices;

    public static function run(callable $operation): mixed
    {
        return self::runPurchasingOperation($operation);
    }
}

final class SalesConcernCoverageHarness
{
    use InteractsWithSalesServices;

    public static function run(callable $operation): mixed
    {
        return self::runSalesOperation($operation);
    }
}

it('covers validation failures in the accounting purchasing and sales filament adapters', function (): void {
    $this->withSession([]);

    foreach ([
        AccountingConcernCoverageHarness::class,
        PurchasingConcernCoverageHarness::class,
        SalesConcernCoverageHarness::class,
    ] as $harness) {
        try {
            $harness::run(static function (): never {
                throw ValidationException::withMessages(['field' => 'Coverage validation failure.']);
            });

            test()->fail('The Filament adapter should halt after a validation failure.');
        } catch (Halt $halt) {
            expect($halt->getPrevious())->toBeInstanceOf(ValidationException::class);
        }
    }
});

it('covers the direct order pricing missing-variant guard', function (): void {
    $order = Order::factory()->create();
    $line = new OrderLine;
    $line->forceFill([
        'order_id' => $order->getKey(),
        'product_variant_id' => 999999999,
        'quantity' => 1,
        'transaction_quantity' => 1,
        'conversion_factor_snapshot' => 1,
    ]);

    expect(fn () => app(DirectOrderLinePricingService::class)->prepare($line))
        ->toThrow(DomainException::class, 'requires a product variant');
});

it('updates an existing unlinked procurement requirement when shortage remains', function (): void {
    $order = Order::factory()->create();
    $variant = ProductVariant::factory()->create();
    $line = OrderLine::factory()->for($order)->for($variant, 'productVariant')->create([
        'quantity' => 10,
        'unit_id' => $variant->unit_id,
    ]);
    $requirement = $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => 10,
        'fulfilled_base_quantity' => 2,
        'status' => 'open',
    ]);

    $active = app(SalesProcurementRequirementService::class)->synchronize($order);

    expect($active)->toHaveCount(1)
        ->and($active->first()?->getKey())->toBe($requirement->getKey())
        ->and((float) $requirement->refresh()->fulfilled_base_quantity)->toBe(2.0)
        ->and($requirement->status)->toBe('open');
});

it('covers shipment confirmation invalid status and incomplete delivery guards', function (): void {
    $planned = Shipment::factory()->create(['status' => ShipmentStatus::Planned]);

    expect(fn () => app(ShipmentService::class)->confirmBySystem($planned))
        ->toThrow(DomainException::class, 'in-transit shipment');

    $draftDelivery = InventoryOperation::factory()->delivery()->draft()->create();
    $shipment = Shipment::factory()->for($draftDelivery, 'delivery')->create([
        'inventory_operation_id' => $draftDelivery->getKey(),
        'status' => ShipmentStatus::InTransit,
    ]);

    expect(fn () => app(ShipmentService::class)->confirmBySystem($shipment))
        ->toThrow(DomainException::class, 'completed customer delivery');
});

it('covers warranty activation delivery and warranty-term edge branches', function (): void {
    $nonCustomerDelivery = InventoryOperation::factory()->receipt()->done()->create();
    $nonCustomerShipment = Shipment::factory()->for($nonCustomerDelivery, 'delivery')->arrived()->create([
        'inventory_operation_id' => $nonCustomerDelivery->getKey(),
        'confirmed_at' => now(),
    ]);

    expect(app(WarrantyActivationService::class)->activateForShipment($nonCustomerShipment))->toBe(0);

    $customer = CustomerProfile::factory()->create();
    $delivery = InventoryOperation::factory()->delivery()->for($customer, 'customer')->done()->create();

    $cases = [
        [
            'variant' => ['warranty_duration_value' => 12, 'warranty_duration_unit' => WarrantyDurationUnit::Months],
            'unit' => ['warranty_started_on' => null, 'warranty_expires_on' => today()->addMonth()],
        ],
        [
            'variant' => ['warranty_duration_value' => 0, 'warranty_duration_unit' => WarrantyDurationUnit::Months],
            'unit' => ['warranty_started_on' => null, 'warranty_expires_on' => null],
        ],
        [
            'variant' => ['warranty_duration_value' => 12, 'warranty_duration_unit' => null],
            'unit' => ['warranty_started_on' => null, 'warranty_expires_on' => null],
        ],
    ];

    foreach ($cases as $case) {
        $variant = ProductVariant::factory()->create($case['variant']);
        $unit = SerializedInventoryUnit::factory()->for($variant, 'productVariant')->create($case['unit']);

        InventoryMovement::factory()->for($variant, 'productVariant')->create([
            'source_type' => 'inventory_operation',
            'source_id' => $delivery->getKey(),
            'serialized_inventory_unit_id' => $unit->getKey(),
            'movement_type' => MovementType::Sale,
            'quantity' => -1,
        ]);
    }

    $shipment = Shipment::factory()->forCustomer($customer)->arrived()->create([
        'inventory_operation_id' => $delivery->getKey(),
        'confirmed_at' => now(),
    ]);

    expect(app(WarrantyActivationService::class)->activateForShipment($shipment))->toBe(0);
});
