<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\OperationType;
use App\Enums\ProductType;
use App\Enums\SerializedInventoryUnitStatus;
use App\Models\InventoryOperation;
use App\Models\ProductVariant;
use App\Models\SerializedInventoryUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryOperationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Deterministic helpers that turn "receive N of variant V" into a valid inventory receipt:
 * lot numbers, expiry dates and serial units are derived from the variant, never random.
 */
final readonly class DemoInventory
{
    public function __construct(private InventoryOperationService $operations) {}

    public static function make(): self
    {
        return app(self::class);
    }

    public function variant(string $productCode, string $suffix): ProductVariant
    {
        return ProductVariant::query()->where('sku', DemoMasterDataSeeder::sku($productCode, $suffix))->firstOrFail();
    }

    public function warehouse(string $code): Warehouse
    {
        return Warehouse::query()->where('code', $code)->firstOrFail();
    }

    public function supplier(string $code): Supplier
    {
        return Supplier::query()->where('code', $code)->firstOrFail();
    }

    /**
     * Identity data a receipt line needs for this variant's tracking type.
     * Expiry materials get a lot with an expiry date, grain a plain lot, machines nothing here.
     *
     * @return array{lot_number?: string, expires_at?: string}
     */
    public function lotIdentity(ProductVariant $variant, string $tag, ?string $expiresOn = null): array
    {
        $type = $variant->productType() ?? throw new LogicException("Variant [{$variant->sku}] has no product type.");
        $lot = 'LOT-'.preg_replace('/^DEMO-/', '', (string) $variant->sku).'-'.$tag;

        return match ($type) {
            ProductType::ExpiryMaterial => [
                'lot_number' => $lot,
                'expires_at' => $expiresOn ?? '2027-06-30',
            ],
            ProductType::Grain => ['lot_number' => $lot],
            ProductType::Machine => [],
        };
    }

    /**
     * Create, ready and complete a receipt in one go.
     *
     * @param  list<array{variant: ProductVariant, quantity: int, tag: string, expires?: string|null}>  $lines
     */
    public function receive(
        User $actor,
        Warehouse $warehouse,
        array $lines,
        string $reference,
        ?Supplier $supplier = null,
        ?string $notes = null,
    ): InventoryOperation {
        $existing = InventoryOperation::query()->where('supplier_reference', $reference)->first();

        if ($existing instanceof InventoryOperation) {
            return $existing;
        }

        $operation = InventoryOperation::query()->create([
            'operation_type' => OperationType::Receipt,
            'destination_warehouse_id' => $warehouse->getKey(),
            'supplier_id' => $supplier?->getKey(),
            'supplier_reference' => $reference,
            'scheduled_at' => now(),
            'responsible_id' => $actor->getKey(),
            'notes' => $notes ?? "[DEMO] {$reference}",
        ]);

        foreach ($lines as $line) {
            $this->addReceiptLine($operation, $line['variant'], $line['quantity'], $line['tag'], $line['expires'] ?? null);
        }

        $this->operations->markReady($operation, $actor);
        $this->operations->complete($operation->refresh(), $actor);

        return $operation->refresh();
    }

    /** Append the line(s) needed to receive `$quantity` of a variant onto a draft receipt. */
    public function addReceiptLine(InventoryOperation $operation, ProductVariant $variant, int $quantity, string $tag, ?string $expires = null): void
    {
        if ($variant->productType() === ProductType::Machine) {
            for ($i = 0; $i < $quantity; $i++) {
                $unit = SerializedInventoryUnit::query()->create([
                    'product_variant_id' => $variant->getKey(),
                    'serial_number' => $this->nextSerial($variant),
                    'status' => SerializedInventoryUnitStatus::Pending,
                ]);

                $operation->lines()->create([
                    'product_variant_id' => $variant->getKey(),
                    'serialized_inventory_unit_id' => $unit->getKey(),
                    'quantity' => 1,
                    'unit_id' => $variant->unit_id,
                ]);
            }

            return;
        }

        $operation->lines()->create([
            'product_variant_id' => $variant->getKey(),
            'quantity' => $quantity,
            'unit_id' => $variant->unit_id,
            ...$this->lotIdentity($variant, $tag, $expires),
        ]);
    }

    /**
     * Give machine / expiring lines of a draft purchase receipt the identity data the stock
     * posting requires (serial per unit, lot + expiry per batch).
     */
    public function completeReceiptIdentity(InventoryOperation $operation, string $tag): void
    {
        foreach ($operation->lines()->with('productVariant')->get() as $line) {
            $variant = $line->productVariant;

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            $type = $variant->productType() ?? throw new LogicException("Variant [{$variant->sku}] has no product type.");

            if ($type === ProductType::Machine && $line->serialized_inventory_unit_id === null) {
                $unit = SerializedInventoryUnit::query()->create([
                    'product_variant_id' => $variant->getKey(),
                    'serial_number' => $this->nextSerial($variant),
                    'status' => SerializedInventoryUnitStatus::Pending,
                ]);
                $line->forceFill(['serialized_inventory_unit_id' => $unit->getKey()])->save();
            } elseif ($type !== ProductType::Machine && $line->lot_number === null) {
                $line->forceFill($this->lotIdentity($variant, $tag))->save();
            }
        }
    }

    public function nextSerial(ProductVariant $variant): string
    {
        $short = preg_replace('/^DEMO-P(\d+)-/', 'SN$1-', (string) $variant->sku);
        $next = SerializedInventoryUnit::query()->where('product_variant_id', $variant->getKey())->count() + 1;

        return sprintf('%s-%04d', $short, $next);
    }

    public function hasOpeningStock(): bool
    {
        return InventoryOperation::query()->where('supplier_reference', 'like', 'DEMO-OPEN-%')->exists();
    }

    public function dateOnly(CarbonInterface|string $value): string
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }

    /** Anchor any model the operation should be traced to (kept for future use by callers). */
    public function trace(InventoryOperation $operation, Model $source): void
    {
        $operation->forceFill([
            'source_document_type' => $source::class,
            'source_document_id' => $source->getKey(),
        ])->save();
    }
}
