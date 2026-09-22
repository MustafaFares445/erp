<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Enums\WarrantyDurationUnit;
use App\Models\InventoryOperation;
use App\Models\SerializedInventoryUnit;
use App\Models\Shipment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Activates customer-warranty snapshots only from a confirmed shipment.
 * Existing snapshots are immutable through this service: confirming the same
 * shipment twice never moves a warranty start date forward.
 */
final readonly class WarrantyActivationService
{
    public function activateForShipment(Shipment $shipment): int
    {
        if (! $shipment->isArrived() || $shipment->confirmed_at === null) {
            return 0;
        }

        $delivery = $shipment->delivery;

        if (! $delivery instanceof InventoryOperation || $delivery->customer_id === null) {
            return 0;
        }

        $unitIds = $delivery->movements()
            ->whereNotNull('serialized_inventory_unit_id')
            ->distinct()
            ->pluck('serialized_inventory_unit_id')
            ->map(static function (mixed $id): int {
                // @codeCoverageIgnoreStart
                // inventory_operation_lines.serialized_inventory_unit_id is an
                // integer foreign key — a plucked value is always numeric.
                if (! is_numeric($id)) {
                    throw new LogicException('A serialized inventory unit identifier must be numeric.');
                }

                // @codeCoverageIgnoreEnd

                return (int) $id;
            })
            ->all();

        if ($unitIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($unitIds, $shipment): int {
            $activated = 0;
            $units = SerializedInventoryUnit::query()
                ->whereKey($unitIds)
                ->with('productVariant')
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                if ($unit->warranty_started_on !== null) {
                    continue;
                }
                if ($unit->warranty_expires_on !== null) {
                    continue;
                }
                $variant = $unit->productVariant;
                $value = $variant?->warranty_duration_value;
                $unitType = $variant?->warranty_duration_unit;
                if (! is_int($value)) {
                    continue;
                }
                if ($value <= 0) {
                    continue;
                }
                if (! $unitType instanceof WarrantyDurationUnit) {
                    continue;
                }

                $startedOn = Carbon::parse($shipment->confirmed_at)->startOfDay();
                $expiresOn = match ($unitType) {
                    WarrantyDurationUnit::Days => $startedOn->copy()->addDays($value),
                    WarrantyDurationUnit::Months => $startedOn->copy()->addMonthsNoOverflow($value),
                    WarrantyDurationUnit::Years => $startedOn->copy()->addYearsNoOverflow($value),
                };

                $unit->forceFill([
                    'warranty_started_on' => $startedOn->toDateString(),
                    'warranty_expires_on' => $expiresOn->toDateString(),
                    'warranty_source_shipment_id' => $shipment->getKey(),
                ])->save();

                activity()
                    ->performedOn($unit)
                    ->withChanges(['attributes' => [
                        'warranty_started_on' => $startedOn->toDateString(),
                        'warranty_expires_on' => $expiresOn->toDateString(),
                        'warranty_source_shipment_id' => $shipment->getKey(),
                    ]])
                    ->withProperties(['source_channel' => 'system', 'shipment_id' => $shipment->getKey()])
                    ->log('support.warranty.activated');

                $activated++;
            }

            return $activated;
        });
    }
}
