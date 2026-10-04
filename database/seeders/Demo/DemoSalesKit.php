<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\InventoryReturn;
use App\Models\InventoryStock;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\PaymentTransaction;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\ReceivableWriteOff;
use App\Models\Refund;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use App\Services\Inventory\InventoryLotService;
use App\Services\Logistics\OutboundDispatchService;
use App\Services\Logistics\OutboundFulfillmentService;
use App\Services\Shipments\ShipmentArrivalConfirmationService;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Shared lookups, document registries and fulfilment helpers for the sales-month scenes.
 *
 * Scenes run in chronological order (see {@see DemoSalesTimeline}) and hand each other the
 * documents they create through the public registries below, keyed by a scenario code.
 */
final class DemoSalesKit
{
    private const array WarehouseOrder = ['WH-MAIN', 'WH-COLD', 'WH-REPAIR'];

    /** @var array<string, Quotation> */
    public array $quotes = [];

    /** @var array<string, Order> */
    public array $orders = [];

    /** @var array<string, list<InventoryOperation>> delivery notes per scenario code (one per source warehouse) */
    public array $deliveries = [];

    /** @var array<string, Invoice> */
    public array $invoices = [];

    /** @var array<string, Payment> */
    public array $payments = [];

    /** @var array<string, CreditNote> */
    public array $creditNotes = [];

    /** @var array<string, InventoryReturn> */
    public array $returns = [];

    /** @var array<string, Refund> */
    public array $refunds = [];

    /** @var array<string, PaymentTransaction> */
    public array $transactions = [];

    /** @var array<string, ReceivableWriteOff> */
    public array $writeOffs = [];

    public int $proofCounter = 0;

    public function __construct(
        public readonly DemoContext $context,
        public readonly DemoInventory $inventory,
    ) {}

    public static function make(DemoContext $context): self
    {
        return new self($context, DemoInventory::make());
    }

    /** `C01` .. `C15` => the matching DEMO-CUST-0NN profile. */
    public function customer(string $key): CustomerProfile
    {
        return CustomerProfile::query()
            ->where('customer_code', sprintf('DEMO-CUST-%03d', (int) mb_substr($key, 1)))
            ->firstOrFail();
    }

    /** `1` .. `6` => DEMO-EMP-00N. */
    public function employee(int $number): EmployeeProfile
    {
        return EmployeeProfile::query()
            ->where('employee_code', sprintf('DEMO-EMP-%03d', $number))
            ->firstOrFail();
    }

    public function term(string $name): PaymentTerm
    {
        return PaymentTerm::query()->where('name', $name)->firstOrFail();
    }

    public function method(string $name): PaymentMethod
    {
        return PaymentMethod::query()->where('name', $name)->firstOrFail();
    }

    /** `P004-35X12` => product variant. */
    public function variant(string $key): ProductVariant
    {
        [$product, $suffix] = explode('-', $key, 2);

        return $this->inventory->variant($product, $suffix);
    }

    /**
     * Quotation / order lines for a `[variant key => quantity]` spec. Prices and tax are left to
     * the pricing services so every line carries the catalogue price and the default VAT.
     *
     * @param  array<string, int>  $spec
     * @return list<array{product_variant_id: int, quantity: int}>
     */
    public function lines(array $spec): array
    {
        $lines = [];

        foreach ($spec as $key => $quantity) {
            $lines[] = ['product_variant_id' => DemoContext::keyOf($this->variant($key)), 'quantity' => $quantity];
        }

        return $lines;
    }

    /**
     * Plan one partial or full outbound fulfilment and return the delivery notes it created
     * (one per source warehouse).
     *
     * @param  array<string, int>  $spec  variant key => quantity
     * @return Collection<int, InventoryOperation>
     */
    public function plan(string $actor, Order $order, array $spec, ?string $tracking = null, bool $overbook = false): Collection
    {
        $user = $this->context->as($actor);
        $before = $order->deliveries()->pluck('inventory_operations.id')->all();

        app(OutboundFulfillmentService::class)->plan($user, $order->refresh(), $this->shipments($spec, $tracking, $overbook));

        return $order->deliveries()
            ->whereNotIn('inventory_operations.id', $before)
            ->orderBy('inventory_operations.id')
            ->get();
    }

    public function prepare(string $actor, InventoryOperation $delivery): InventoryOperation
    {
        return app(OutboundFulfillmentService::class)->prepare($this->context->as($actor), $delivery->refresh());
    }

    public function dispatch(string $actor, InventoryOperation $delivery, string $tracking): Shipment
    {
        return app(OutboundDispatchService::class)->dispatch(
            $this->context->as($actor),
            $delivery->refresh(),
            ['tracking_number' => $tracking],
        );
    }

    public function arrive(string $actor, InventoryOperation $delivery, string $note): void
    {
        $shipment = $delivery->refresh()->shipment()->firstOrFail();

        app(ShipmentArrivalConfirmationService::class)->confirmByAdmin(
            $shipment,
            $this->context->as($actor),
            [],
            $note,
        );
    }

    /**
     * Build `OutboundFulfillmentService::plan()` input for a `[variant key => quantity]` spec,
     * taking stock from the main store first, then cold chain, then the repair bench, and
     * naming lots (earliest expiry first) or serial units where the variant needs them.
     *
     * @param  array<string, int>  $spec
     * @return list<array<string, mixed>>
     */
    private function shipments(array $spec, ?string $tracking, bool $overbook): array
    {
        $byWarehouse = [];
        $lots = app(InventoryLotService::class);

        foreach ($spec as $key => $quantity) {
            $variant = $this->variant($key);
            $left = (float) $quantity;

            foreach (self::WarehouseOrder as $code) {
                if ($left <= 0.0) {
                    break;
                }

                $warehouse = $this->inventory->warehouse($code);
                $stock = InventoryStock::query()
                    ->where('product_variant_id', DemoContext::keyOf($variant))
                    ->where('warehouse_id', DemoContext::keyOf($warehouse))
                    ->first();
                $available = $stock instanceof InventoryStock ? (float) $stock->available_quantity : 0.0;

                if (! $overbook) {
                    $available -= array_sum($this->pendingLots(DemoContext::keyOf($variant), DemoContext::keyOf($warehouse))) + count($this->pendingSerials(DemoContext::keyOf($variant), DemoContext::keyOf($warehouse)));
                }

                if ($available <= 0.0) {
                    continue;
                }

                $take = min($left, $available);

                foreach ($this->assignmentsFor($variant, DemoContext::keyOf($warehouse), $take, $lots, $overbook) as $assignment) {
                    $byWarehouse[$code]['assignments'][] = $assignment;
                }

                $left -= $take;
            }

            if ($left > 0.0) {
                throw new LogicException("Demo stock for [{$key}] cannot cover {$quantity} units (short {$left}).");
            }
        }

        $shipments = [];

        foreach ($byWarehouse as $code => $shipment) {
            $shipments[] = [
                'warehouse_id' => DemoContext::keyOf($this->inventory->warehouse($code)),
                'tracking_number' => $tracking,
                'attachments' => [],
                'delivery_type' => null,
                'assignments' => $shipment['assignments'],
            ];
        }

        return $shipments;
    }

    /** @return list<array{product_variant_id: int, quantity: float, inventory_lot_id: int|null, serialized_inventory_unit_ids: list<int>}> */
    private function assignmentsFor(ProductVariant $variant, int $warehouseId, float $quantity, InventoryLotService $lots, bool $overbook): array
    {
        $variantId = DemoContext::keyOf($variant);

        $pendingLots = $overbook ? [] : $this->pendingLots($variantId, $warehouseId);
        $pendingSerials = $overbook ? [] : $this->pendingSerials($variantId, $warehouseId);

        if ($variant->track_serials) {
            $ids = SerializedInventoryUnit::query()
                ->where('product_variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'available')
                ->whereNotIn('id', $pendingSerials)
                ->orderBy('id')
                ->limit((int) $quantity)
                ->pluck('id')
                ->map(static fn (mixed $id): int => DemoContext::intOf($id))
                ->all();

            return [[
                'product_variant_id' => $variantId,
                'quantity' => (float) count($ids),
                'inventory_lot_id' => null,
                'serialized_inventory_unit_ids' => array_values($ids),
            ]];
        }

        if (! $variant->track_batches) {
            return [[
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'inventory_lot_id' => null,
                'serialized_inventory_unit_ids' => [],
            ]];
        }

        $assignments = [];
        $left = $quantity;

        /** @var InventoryLot $lot */
        foreach ($lots->availableLots($variantId, $warehouseId) as $lot) {
            if ($left <= 0.0) {
                break;
            }

            $allocated = min($left, (float) $lot->availableQuantity($warehouseId) - ($pendingLots[DemoContext::keyOf($lot)] ?? 0.0));

            if ($allocated <= 0.0) {
                continue;
            }

            $assignments[] = [
                'product_variant_id' => $variantId,
                'quantity' => round($allocated, 6),
                'inventory_lot_id' => DemoContext::keyOf($lot),
                'serialized_inventory_unit_ids' => [],
            ];
            $left -= $allocated;
        }

        return $assignments;
    }

    /**
     * Quantity per lot already promised to planned (Draft) deliveries. Planning does not
     * reserve stock, so without this two deliveries planned before either is prepared would
     * claim the same lot.
     *
     * @return array<int, float>
     */
    private function pendingLots(int $variantId, int $warehouseId): array
    {
        $pending = [];

        foreach ($this->draftDeliveryLines($variantId, $warehouseId) as $line) {
            if ($line->inventory_lot_id !== null) {
                $pending[(int) $line->inventory_lot_id] = ($pending[(int) $line->inventory_lot_id] ?? 0.0) + (float) ($line->base_quantity ?? $line->quantity);
            }
        }

        return $pending;
    }

    /** @return list<int> */
    private function pendingSerials(int $variantId, int $warehouseId): array
    {
        return array_values($this->draftDeliveryLines($variantId, $warehouseId)
            ->pluck('serialized_inventory_unit_id')
            ->filter()
            ->map(static fn (mixed $id): int => DemoContext::intOf($id))
            ->all());
    }

    /** @return Collection<int, InventoryOperationLine> */
    private function draftDeliveryLines(int $variantId, int $warehouseId): Collection
    {
        return InventoryOperationLine::query()
            ->where('product_variant_id', $variantId)
            ->whereHas('operation', static fn ($operation) => $operation
                ->where('operation_type', 'delivery')
                ->where('stage', 'draft')
                ->where('source_warehouse_id', $warehouseId))
            ->get();
    }
}
