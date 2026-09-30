<?php

declare(strict_types=1);

use App\Filament\Pages\PurchaseNeeds;
use App\Filament\Resources\PurchaseInbounds\Pages\ViewPurchaseInbound;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ProductVariant;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundLine;
use App\Models\Supplier;
use App\Models\SupplierProductReference;
use App\Models\User;
use App\Services\Inventory\LogisticsInboundProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('covers allocatable inbound line options and positive-quantity filtering', function (): void {
    $inbound = PurchaseInbound::factory()->create();
    $first = PurchaseInboundLine::factory()->create(['purchase_inbound_id' => $inbound->getKey()]);
    $second = PurchaseInboundLine::factory()->create(['purchase_inbound_id' => $inbound->getKey()]);

    $fake = new class($first->getKey(), $second->getKey())
    {
        public function __construct(
            private readonly int $firstId,
            private readonly int $secondId,
        ) {}

        public function projectLine(PurchaseInboundLine $line): object
        {
            if ($line->getKey() === $this->firstId) {
                return (object) [
                    'currentlyAllocatableBaseQuantity' => '2.500000',
                    'sku' => 'SKU-COV-40',
                    'product' => 'Coverage Product',
                ];
            }

            if ($line->getKey() === $this->secondId) {
                return (object) [
                    'currentlyAllocatableBaseQuantity' => '0.000000',
                    'sku' => 'SKU-SKIP-40',
                    'product' => 'Skipped Product',
                ];
            }

            throw new RuntimeException('Unexpected inbound line.');
        }
    };
    app()->instance(LogisticsInboundProjectionService::class, $fake);

    $method = new ReflectionMethod(ViewPurchaseInbound::class, 'allocatableLineOptions');
    $options = $method->invoke(null, $inbound);

    expect($options)->toHaveKey($first->getKey())
        ->not->toHaveKey($second->getKey())
        ->and($options[$first->getKey()])->toContain('SKU-COV-40')
        ->toContain('Coverage Product');
});

it('covers purchase inbound header action visibility and purchase-order link', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);
    Gate::before(static fn (): bool => true);

    $inbound = PurchaseInbound::factory()->create();
    PurchaseInboundLine::factory()->create(['purchase_inbound_id' => $inbound->getKey()]);

    app()->instance(LogisticsInboundProjectionService::class, new class
    {
        public function projectLine(PurchaseInboundLine $line): object
        {
            return (object) [
                'currentlyAllocatableBaseQuantity' => '1.000000',
                'sku' => 'SKU-ACTION-40',
                'product' => 'Action Product',
            ];
        }
    });

    $page = new ReflectionClass(ViewPurchaseInbound::class)->newInstanceWithoutConstructor();
    $actions = collect($page->getHeaderActions())->keyBy(static fn ($action): string => $action->getName());

    $allocate = $actions->get('allocateInbound');
    $receipt = $actions->get('createOrOpenReceipt');
    $purchaseOrder = $actions->get('purchaseOrder');

    expect($allocate)->not->toBeNull()
        ->and($allocate->record($inbound)->isVisible())->toBeTrue()
        ->and($receipt)->not->toBeNull()
        ->and($receipt->record($inbound)->isVisible())->toBeFalse()
        ->and($purchaseOrder)->not->toBeNull()
        ->and($purchaseOrder->record($inbound)->getUrl())->toContain((string) $inbound->purchase_order_id);
});

it('covers purchase needs sales-demand projection and search filtering', function (): void {
    $order = Order::factory()->create(['order_number' => 'SO-COV-NEEDLE-40']);
    $variant = ProductVariant::factory()->create([
        'name' => 'Coverage Needle Variant',
        'sku' => 'SKU-NEEDLE-40',
    ]);
    $line = OrderLine::factory()
        ->for($order)
        ->for($variant, 'productVariant')
        ->create([
            'quantity' => '3.000',
            'unit_id' => $variant->unit_id,
        ]);

    $order->procurementRequirements()->create([
        'order_line_id' => $line->getKey(),
        'product_variant_id' => $variant->getKey(),
        'required_base_quantity' => '3.000000',
        'fulfilled_base_quantity' => '1.000000',
        'status' => 'open',
    ]);

    $supplier = Supplier::factory()->create(['is_active' => true]);
    SupplierProductReference::factory()->create([
        'supplier_id' => $supplier->getKey(),
        'product_variant_id' => $variant->getKey(),
        'is_active' => true,
    ]);

    $page = new PurchaseNeeds;
    $page->search = 'needle';

    $rows = $page->needs();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['source'])->toBe('Sales Order')
        ->and($rows[0]['source_reference'])->toBe('SO-COV-NEEDLE-40')
        ->and($rows[0]['sku'])->toBe('SKU-NEEDLE-40')
        ->and($rows[0]['supplier_count'])->toBe(1)
        ->and($rows[0]['next_action'])->toBe('Create Purchase Order');

    $page->search = 'definitely-not-present';
    expect($page->needs())->toBe([]);
});
