<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\TicketType;
use App\Models\CustomerProfile;
use App\Models\InventoryLot;
use App\Models\InventoryOperation;
use App\Models\InventoryOperationLine;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\SupportPermissionSeeder;

/** Shared builders for the product / lot quality complaint tests. */
final class QualityFixtures
{
    public static function seedPermissions(): void
    {
        (new SupportPermissionSeeder)->run();
        (new InventoryPermissionSeeder)->run();
        config(['support.product_quality_enabled' => true, 'support.lot_complaint_threshold' => 3]);
    }

    public static function manager(): User
    {
        return InstallationFixtures::manager();
    }

    /** A completed, non-serialized delivery line of a lot-tracked product to the customer. */
    public static function delivered(CustomerProfile $customer, string $quantity = '10', ?InventoryLot $lot = null, ?ProductVariant $variant = null): InventoryOperationLine
    {
        $variant ??= $lot instanceof InventoryLot ? ProductVariant::query()->findOrFail($lot->product_variant_id) : ProductVariant::factory()->create();
        $lot ??= InventoryLot::factory()->create(['product_variant_id' => $variant->id, 'lot_number' => 'LOT-'.fake()->unique()->numerify('####')]);
        $unit = Unit::factory()->create();
        $operation = InventoryOperation::factory()->delivery()->done()->create(['customer_id' => $customer->getKey()]);

        $line = new InventoryOperationLine;
        $line->forceFill([
            'inventory_operation_id' => $operation->getKey(),
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'transaction_quantity' => $quantity,
            'unit_id' => $unit->id,
            'transaction_unit_id' => $unit->id,
            'inventory_lot_id' => $lot->id,
            'lot_number' => $lot->lot_number,
            'is_picked' => true,
        ])->save();

        return $line;
    }

    public static function ticket(CustomerProfile $customer, TicketType $type = TicketType::ProductQualityIssue): Ticket
    {
        return Ticket::factory()->create(['customer_id' => $customer->getKey(), 'type' => $type]);
    }

    /** @return list<array{original_inventory_operation_line_id: int, quantity: string, notes?: string}> */
    public static function lines(InventoryOperationLine ...$lines): array
    {
        return array_map(static fn (InventoryOperationLine $line): array => [
            'original_inventory_operation_line_id' => $line->id,
            'quantity' => '2',
        ], $lines);
    }

    public static function supplier(): Supplier
    {
        return Supplier::factory()->create();
    }
}
