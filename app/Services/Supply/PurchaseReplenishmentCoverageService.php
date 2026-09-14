<?php

declare(strict_types=1);

namespace App\Services\Supply;

use App\Enums\PurchaseOrderStatus;
use App\Enums\ReplenishmentCoverageSourceType;
use App\Enums\ReplenishmentCoverageStatus;
use App\Models\PurchaseInbound;
use App\Models\PurchaseInboundLine;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentCoverage;
use App\Models\ReplenishmentRequirement;
use App\Models\WarehouseReplenishmentPolicy;
use App\Services\Inventory\ReplenishmentCoverageService;
use App\Services\Inventory\ReplenishmentRequirementService;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Synchronizes purchase-order incoming supply into warehouse replenishment.
 *
 * Phase 4 makes the warehouse allocation, not the whole PO line, the source of
 * incoming quantity. A 100-unit PO split A=60/B=40 therefore contributes at
 * most 60 to A's requirement and 40 to B's requirement. Completed receipts
 * reduce only the allocation/warehouse they actually landed in.
 */
final readonly class PurchaseReplenishmentCoverageService
{
    private const int QUANTITY_SCALE = 6;

    public function __construct(
        private ReplenishmentCoverageService $coverages,
        private ReplenishmentRequirementService $requirements,
        private PurchaseInboundIncomingSupplyService $incomingSupply,
    ) {}

    public function syncForOrder(PurchaseOrder $order): void
    {
        if (! in_array($order->status, [PurchaseOrderStatus::Accepted, PurchaseOrderStatus::PartiallyReceived], true)) {
            $this->releaseForOrder($order);

            return;
        }

        $order->loadMissing('purchaseInbound.lines.allocations', 'purchaseInbound.lines.purchaseOrderLine');
        $inbound = $order->purchaseInbound;

        if (! $inbound instanceof PurchaseInbound) {
            return;
        }

        foreach ($inbound->lines as $line) {
            $this->syncForInboundLine($line);
        }
    }

    public function syncForInboundLine(PurchaseInboundLine $line): void
    {
        $line->loadMissing('allocations', 'purchaseOrderLine');
        $purchaseLine = $line->purchaseOrderLine;
        $sourceId = $purchaseLine->id;

        /** @var Collection<int, ReplenishmentCoverage> $existing */
        $existing = ReplenishmentCoverage::query()
            ->with('requirement')
            ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
            ->where('source_id', $sourceId)
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->get();

        if ($line->allocations->isEmpty()) {
            $this->releaseCoverages($existing);

            return;
        }

        /** @var array<int, true> $retainedRequirementIds */
        $retainedRequirementIds = [];

        foreach ($line->allocations as $allocation) {
            // Reuse the already-loaded aggregate so the calculator can apply the
            // exact same single-allocation legacy fallback without reloading it.
            $allocation->setRelation('purchaseInboundLine', $line);
            $incoming = $this->incomingSupply->remainingForAllocation($allocation);
            // A multi-allocation historical row with no known allocation quantity
            // is intentionally not guessed. Any old coverage not backed by a
            // deterministic current allocation is released below.
            if ($incoming === null) {
                continue;
            }
            if (bccomp($incoming, '0.000000', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            $requirement = ReplenishmentRequirement::query()
                ->where('warehouse_id', $allocation->warehouse_id)
                ->where('product_variant_id', $purchaseLine->product_variant_id)
                ->active()
                ->oldest('id')
                ->first();

            if (! $requirement instanceof ReplenishmentRequirement) {
                continue;
            }

            $requirementKey = $requirement->getAttribute('id');

            if (! is_int($requirementKey)) {
                throw new DomainException('Replenishment requirement requires an integer id before purchase coverage can be synchronized.');
            }

            $requirementId = $requirementKey;
            $current = $existing->first(function (ReplenishmentCoverage $coverage) use ($requirementId): bool {
                $coverageRequirementId = $coverage->getAttribute('replenishment_requirement_id');

                return is_int($coverageRequirementId) && $coverageRequirementId === $requirementId;
            });
            $currentQuantity = $current instanceof ReplenishmentCoverage
                ? $this->decimal($current->getAttribute('covered_base_quantity'))
                : '0.000000';
            $availableCapacity = $this->availableCapacity($requirement, $currentQuantity);
            $covered = $this->minimum($incoming, $availableCapacity);

            if (bccomp($covered, '0.000000', self::QUANTITY_SCALE) <= 0) {
                continue;
            }

            $this->coverages->attach(
                $requirement,
                ReplenishmentCoverageSourceType::PurchaseOrderLine,
                $sourceId,
                (float) $covered,
            );
            $retainedRequirementIds[$requirementId] = true;
        }

        foreach ($existing as $coverage) {
            $requirementId = $coverage->getAttribute('replenishment_requirement_id');

            if (! is_int($requirementId) || ! isset($retainedRequirementIds[$requirementId])) {
                $this->coverages->release($coverage);
            }
        }
    }

    public function releaseForOrder(PurchaseOrder $order): void
    {
        $lineIds = $order->lines()->pluck('id');

        if ($lineIds->isEmpty()) {
            return;
        }

        $coverages = ReplenishmentCoverage::query()
            ->with('requirement.policy')
            ->where('source_type', ReplenishmentCoverageSourceType::PurchaseOrderLine->value)
            ->whereIn('source_id', $lineIds)
            ->where('status', ReplenishmentCoverageStatus::Active->value)
            ->get();
        $policies = [];

        foreach ($coverages as $coverage) {
            $coverageRequirement = $coverage->requirement;

            if ($coverageRequirement instanceof ReplenishmentRequirement) {
                $policy = $coverageRequirement->policy;

                if ($policy instanceof WarehouseReplenishmentPolicy) {
                    $policyKey = $policy->getKey();

                    if (is_int($policyKey) || is_string($policyKey)) {
                        $policies[$policyKey] = $policy;
                    }
                }
            }

            $this->coverages->release($coverage);
        }

        foreach ($policies as $policy) {
            $this->requirements->sync($policy);
        }
    }

    /**
     * @param  numeric-string  $currentQuantity
     * @return numeric-string
     */
    private function availableCapacity(ReplenishmentRequirement $requirement, string $currentQuantity): string
    {
        $required = $this->decimal($requirement->getAttribute('required_base_quantity'));
        $alreadyCovered = $this->decimal($requirement->getAttribute('covered_base_quantity'));
        $withoutCurrent = $this->nonNegativeDifference($alreadyCovered, $currentQuantity);

        return $this->nonNegativeDifference($required, $withoutCurrent);
    }

    /**
     * @param  numeric-string  $minuend
     * @param  numeric-string  $subtrahend
     * @return numeric-string
     */
    private function nonNegativeDifference(string $minuend, string $subtrahend): string
    {
        $difference = bcsub($minuend, $subtrahend, self::QUANTITY_SCALE);

        return bccomp($difference, '0.000000', self::QUANTITY_SCALE) === -1
            ? '0.000000'
            : $difference;
    }

    /**
     * @param  numeric-string  $left
     * @param  numeric-string  $right
     * @return numeric-string
     */
    private function minimum(string $left, string $right): string
    {
        return bccomp($left, $right, self::QUANTITY_SCALE) <= 0 ? $left : $right;
    }

    /** @return numeric-string */
    private function decimal(mixed $quantity): string
    {
        if (! is_numeric($quantity)) {
            throw new DomainException('Replenishment base quantity must be numeric.');
        }

        /** @var numeric-string $numericQuantity */
        $numericQuantity = (string) $quantity;

        return bcadd('0.000000', $numericQuantity, self::QUANTITY_SCALE);
    }

    /** @param Collection<int, ReplenishmentCoverage> $coverages */
    private function releaseCoverages(Collection $coverages): void
    {
        foreach ($coverages as $coverage) {
            $this->coverages->release($coverage);
        }
    }
}
