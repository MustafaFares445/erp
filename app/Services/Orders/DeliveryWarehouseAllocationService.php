<?php

declare(strict_types=1);

namespace App\Services\Orders;

use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type WarehouseCandidate from WarehouseStockService
 *
 * @phpstan-type Demand array<int, float>
 * @phpstan-type Assignment array{product_variant_id: int, quantity: float, allocation_source: string}
 * @phpstan-type Shipment array{warehouse_id: int, assignments: list<Assignment>}
 */
final readonly class DeliveryWarehouseAllocationService
{
    private const float QuantityTolerance = 0.0001;

    public function __construct(
        private DistanceCalculator $distanceCalculator,
        private WarehouseStockService $warehouseStockService,
    ) {}

    /**
     * @param  array<array-key, mixed>  $requestedLines
     * @return list<Shipment>
     */
    public function allocate(float $destinationLatitude, float $destinationLongitude, array $requestedLines): array
    {
        $demands = $this->demands($requestedLines);
        $candidates = $this->warehouseStockService->eligibleCandidates(array_keys($demands));
        $this->assertTotalAvailability($demands, $candidates);
        $selectedWarehouseIds = $this->selectedWarehouses($candidates, $demands, $destinationLatitude, $destinationLongitude);

        return $this->distributeDemand($demands, $candidates, $selectedWarehouseIds);
    }

    /**
     * @param  array<array-key, mixed>  $requestedLines
     * @param  array<array-key, mixed>  $shipments
     */
    public function validate(array $requestedLines, array $shipments): void
    {
        $demands = $this->demands($requestedLines);
        $assignedQuantities = $this->assignments($shipments, $demands);

        foreach ($demands as $variantId => $demandedQuantity) {
            $assignedQuantity = array_sum(array_map(
                static fn (array $warehouseAssignments): float => $warehouseAssignments[$variantId] ?? 0.0,
                $assignedQuantities,
            ));

            if (abs($assignedQuantity - $demandedQuantity) > self::QuantityTolerance) {
                throw ValidationException::withMessages(['shipments' => 'Every selected product must be fully assigned, without exceeding its requested quantity.']);
            }
        }

        $availableCandidates = $this->warehouseStockService->eligibleCandidates(array_keys($demands));

        foreach ($assignedQuantities as $warehouseId => $warehouseAssignments) {
            foreach ($warehouseAssignments as $variantId => $assignedQuantity) {
                $availableQuantity = $availableCandidates[$warehouseId]['stocks'][$variantId] ?? 0.0;

                if ($assignedQuantity > $availableQuantity + self::QuantityTolerance) {
                    throw ValidationException::withMessages(['shipments' => 'A warehouse no longer has enough available stock for this allocation.']);
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $requestedLines
     * @return Demand
     */
    private function demands(array $requestedLines): array
    {
        $demands = [];

        foreach ($requestedLines as $requestedLine) {
            if (! is_array($requestedLine)) {
                throw ValidationException::withMessages(['products' => 'Each selected product needs a valid quantity.']);
            }

            $variantId = $this->integer($requestedLine['product_variant_id'] ?? null);
            $quantity = $this->positiveFloat($requestedLine['quantity'] ?? null);

            if ($variantId === null || $quantity === null) {
                throw ValidationException::withMessages(['products' => 'Each selected product needs a valid quantity.']);
            }

            if (array_key_exists($variantId, $demands)) {
                throw ValidationException::withMessages(['products' => 'Each product variant may only be requested once.']);
            }

            $demands[$variantId] = $quantity;
        }

        if ($demands === []) {
            throw ValidationException::withMessages(['products' => 'Select at least one product before continuing.']);
        }

        return $demands;
    }

    /**
     * @param  Demand  $demands
     * @param  array<int, WarehouseCandidate>  $candidates
     */
    private function assertTotalAvailability(array $demands, array $candidates): void
    {
        foreach ($demands as $variantId => $demandedQuantity) {
            $availableQuantity = array_sum(array_map(
                static fn (array $candidate): float => $candidate['stocks'][$variantId] ?? 0.0,
                $candidates,
            ));

            if ($availableQuantity + self::QuantityTolerance < $demandedQuantity) {
                throw ValidationException::withMessages(['products' => 'There is not enough eligible warehouse stock to fulfil every requested product.']);
            }
        }
    }

    /**
     * @param  array<int, WarehouseCandidate>  $candidates
     * @param  Demand  $demands
     * @return list<int>
     */
    private function selectedWarehouses(array $candidates, array $demands, float $destinationLatitude, float $destinationLongitude): array
    {
        $remaining = $demands;
        $selectedWarehouseIds = [];

        while ($remaining !== []) {
            $warehouseId = $this->bestWarehouseId($candidates, $remaining, $destinationLatitude, $destinationLongitude);

            if ($warehouseId === null) {
                throw ValidationException::withMessages(['products' => 'No eligible warehouse can fulfil the remaining requested stock.']);
            }

            $selectedWarehouseIds[] = $warehouseId;

            foreach ($remaining as $variantId => $requestedQuantity) {
                $remainingQuantity = $requestedQuantity - ($candidates[$warehouseId]['stocks'][$variantId] ?? 0.0);

                if ($remainingQuantity <= self::QuantityTolerance) {
                    unset($remaining[$variantId]);

                    continue;
                }

                $remaining[$variantId] = $remainingQuantity;
            }

            unset($candidates[$warehouseId]);
        }

        return $selectedWarehouseIds;
    }

    /**
     * @param  array<int, WarehouseCandidate>  $candidates
     * @param  Demand  $remaining
     */
    private function bestWarehouseId(array $candidates, array $remaining, float $destinationLatitude, float $destinationLongitude): ?int
    {
        $bestWarehouseId = null;
        $bestScore = null;

        foreach ($candidates as $warehouseId => $candidate) {
            $score = $this->candidateScore($candidate, $remaining, $destinationLatitude, $destinationLongitude, $warehouseId);

            if ($score[0] === 0) {
                continue;
            }

            if ($bestScore === null || $score > $bestScore) {
                $bestWarehouseId = $warehouseId;
                $bestScore = $score;
            }
        }

        return $bestWarehouseId;
    }

    /**
     * @param  WarehouseCandidate  $candidate
     * @param  Demand  $remaining
     * @return array{int, float, float, float, int}
     */
    private function candidateScore(array $candidate, array $remaining, float $destinationLatitude, float $destinationLongitude, int $warehouseId): array
    {
        $completeLines = 0;
        $requestedQuantity = 0.0;
        $allocatedQuantity = 0.0;

        foreach ($remaining as $variantId => $quantity) {
            $availableQuantity = $candidate['stocks'][$variantId] ?? 0.0;
            $requestedQuantity += $quantity;
            $allocatedQuantity += min($quantity, $availableQuantity);

            if ($availableQuantity + self::QuantityTolerance >= $quantity) {
                $completeLines++;
            }
        }

        $warehouse = $candidate['warehouse'];
        $distance = $this->distanceCalculator->kilometers(
            $destinationLatitude,
            $destinationLongitude,
            (float) $warehouse->latitude,
            (float) $warehouse->longitude,
        );

        return [
            $completeLines,
            $requestedQuantity === 0.0 ? 0.0 : $allocatedQuantity / $requestedQuantity,
            $allocatedQuantity,
            -$distance,
            -$warehouseId,
        ];
    }

    /**
     * @param  Demand  $demands
     * @param  array<int, WarehouseCandidate>  $candidates
     * @param  list<int>  $selectedWarehouseIds
     * @return list<Shipment>
     */
    private function distributeDemand(array $demands, array $candidates, array $selectedWarehouseIds): array
    {
        $remainingStock = array_map(static fn (array $candidate): array => $candidate['stocks'], $candidates);
        $assignments = [];

        foreach ($demands as $variantId => $demandedQuantity) {
            $completeWarehouseId = collect($selectedWarehouseIds)->first(
                fn (int $warehouseId): bool => ($remainingStock[$warehouseId][$variantId] ?? 0.0) + self::QuantityTolerance >= $demandedQuantity,
            );

            if (is_int($completeWarehouseId)) {
                $assignments[$completeWarehouseId][] = $this->assignment($variantId, $demandedQuantity);
                $remainingStock[$completeWarehouseId][$variantId] -= $demandedQuantity;

                continue;
            }

            $remainingQuantity = $demandedQuantity;

            foreach ($selectedWarehouseIds as $warehouseId) {
                $availableQuantity = $remainingStock[$warehouseId][$variantId] ?? 0.0;
                $allocatedQuantity = min($remainingQuantity, $availableQuantity);

                if ($allocatedQuantity <= self::QuantityTolerance) {
                    continue;
                }

                $assignments[$warehouseId][] = $this->assignment($variantId, $allocatedQuantity);
                $remainingStock[$warehouseId][$variantId] -= $allocatedQuantity;
                $remainingQuantity -= $allocatedQuantity;

                if ($remainingQuantity <= self::QuantityTolerance) {
                    break;
                }
            }
        }

        $shipments = [];

        foreach ($selectedWarehouseIds as $warehouseId) {
            if (! isset($assignments[$warehouseId])) {
                continue;
            }

            $shipments[] = [
                'warehouse_id' => $warehouseId,
                'assignments' => $assignments[$warehouseId],
            ];
        }

        return $shipments;
    }

    /** @return Assignment */
    private function assignment(int $variantId, float $quantity): array
    {
        return [
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
            'allocation_source' => 'automatic',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $shipments
     * @param  Demand  $demands
     * @return array<int, array<int, float>>
     */
    private function assignments(array $shipments, array $demands): array
    {
        $assignedQuantities = [];

        foreach ($shipments as $shipment) {
            if (! is_array($shipment)) {
                throw ValidationException::withMessages(['shipments' => 'Each warehouse allocation must be valid.']);
            }

            $warehouseId = $this->integer($shipment['warehouse_id'] ?? null);
            $shipmentAssignments = $shipment['assignments'] ?? null;

            if ($warehouseId === null || ! is_array($shipmentAssignments) || $shipmentAssignments === []) {
                throw ValidationException::withMessages(['shipments' => 'Remove warehouses that have no assigned products.']);
            }

            if (isset($assignedQuantities[$warehouseId])) {
                throw ValidationException::withMessages(['shipments' => 'Each selected warehouse may only have one shipment.']);
            }

            foreach ($shipmentAssignments as $assignment) {
                if (! is_array($assignment)) {
                    throw ValidationException::withMessages(['shipments' => 'Each warehouse assignment needs a product and quantity.']);
                }

                $variantId = $this->integer($assignment['product_variant_id'] ?? null);
                $quantity = $this->positiveFloat($assignment['quantity'] ?? null);

                if ($variantId === null || $quantity === null) {
                    throw ValidationException::withMessages(['shipments' => 'Each warehouse assignment needs a product and quantity.']);
                }

                if (! array_key_exists($variantId, $demands)) {
                    throw ValidationException::withMessages(['shipments' => 'A shipment contains a product that was not selected.']);
                }

                $assignedQuantities[$warehouseId][$variantId] = ($assignedQuantities[$warehouseId][$variantId] ?? 0.0) + $quantity;
            }
        }

        if ($assignedQuantities === []) {
            throw ValidationException::withMessages(['shipments' => 'Assign every selected product to a warehouse.']);
        }

        return $assignedQuantities;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $quantity = (float) $value;

        return $quantity > self::QuantityTolerance ? $quantity : null;
    }
}
