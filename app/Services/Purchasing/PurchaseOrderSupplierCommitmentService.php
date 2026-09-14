<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\SupplierConfirmationStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SupplierConfirmation;
use App\Models\SupplierConfirmationItem;
use LogicException;

/**
 * Resolves the supplier-backed physical quantity available to Logistics.
 * Commercial order quantity remains the absolute ceiling; confirmation can
 * only narrow that ceiling and never rewrites historical allocations.
 */
final readonly class PurchaseOrderSupplierCommitmentService
{
    private const int SCALE = 6;

    /** @return numeric-string */
    public function allocationCeiling(PurchaseOrderLine $line): string
    {
        return $this->quantities($line)['confirmed'];
    }

    /**
     * @return array{
     *   ordered: numeric-string,
     *   confirmed: numeric-string,
     *   backordered: numeric-string,
     *   unavailable: numeric-string,
     *   allocated: numeric-string,
     *   currently_allocatable: numeric-string,
     *   over_allocated: bool,
     *   awaiting_confirmation: bool
     * }
     */
    public function quantities(PurchaseOrderLine $line): array
    {
        $ordered = $this->ordered($line);
        $order = $line->purchaseOrder()->with('supplier')->first();

        if (! $order instanceof PurchaseOrder) {
            throw new LogicException('Purchase order line has no purchase order.');
        }

        $confirmed = $ordered;
        $backordered = '0.000000';
        $unavailable = '0.000000';
        $awaitingConfirmation = false;

        if ($order->supplier->requires_confirmation) {
            [$confirmed, $backordered, $unavailable, $awaitingConfirmation] =
                $this->confirmationQuantities($order, $line, $ordered);
        }

        $allocated = $line->purchaseInboundLine?->allocatedBaseQuantity() ?? '0.000000';
        $currentlyAllocatable = bcsub($confirmed, $allocated, self::SCALE);
        $overAllocated = bccomp($currentlyAllocatable, '0.000000', self::SCALE) === -1;

        if ($overAllocated) {
            $currentlyAllocatable = '0.000000';
        }

        return [
            'ordered' => $ordered,
            'confirmed' => $confirmed,
            'backordered' => $backordered,
            'unavailable' => $unavailable,
            'allocated' => $allocated,
            'currently_allocatable' => $currentlyAllocatable,
            'over_allocated' => $overAllocated,
            'awaiting_confirmation' => $awaitingConfirmation,
        ];
    }

    /** @return numeric-string */
    private function ordered(PurchaseOrderLine $line): string
    {
        if ($line->base_quantity === null) {
            throw new LogicException('Supplier commitment requires a normalized purchase order line base quantity.');
        }

        return bcadd('0.000000', $line->base_quantity, self::SCALE);
    }

    /**
     * @param numeric-string $ordered
     * @return array{0: numeric-string, 1: numeric-string, 2: numeric-string, 3: bool}
     */
    private function confirmationQuantities(
        PurchaseOrder $order,
        PurchaseOrderLine $line,
        string $ordered,
    ): array {
        $items = SupplierConfirmationItem::query()
            ->where('purchase_order_line_id', $line->id)
            ->orderBy('id')
            ->get([
                'id',
                'confirmation_status',
                'requested_base_quantity',
                'confirmed_base_quantity',
            ]);

        if ($items->isEmpty()) {
            $confirmation = $order->confirmations()->latest('id')->first();

            if (! $confirmation instanceof SupplierConfirmation) {
                return ['0.000000', '0.000000', '0.000000', true];
            }

            return $this->legacyHeaderQuantities($confirmation, $ordered);
        }

        $confirmed = '0.000000';
        $unavailable = '0.000000';
        $hasPendingEvidence = false;
        $hasAnsweredEvidence = false;

        foreach ($items as $item) {
            if ($item->confirmation_status === SupplierConfirmationStatus::Pending) {
                $hasPendingEvidence = true;

                continue;
            }

            $hasAnsweredEvidence = true;
            $remaining = $this->nonNegativeSubtract($ordered, bcadd($confirmed, $unavailable, self::SCALE));

            if (bccomp($remaining, '0.000000', self::SCALE) !== 1) {
                continue;
            }

            $requested = $item->requested_base_quantity ?? $remaining;

            if ($item->confirmation_status === SupplierConfirmationStatus::Rejected) {
                $unavailable = bcadd($unavailable, $this->capAtOrdered($requested, $remaining), self::SCALE);

                continue;
            }

            $quantity = $item->confirmed_base_quantity ?? $requested;
            $confirmed = bcadd($confirmed, $this->capAtOrdered($quantity, $remaining), self::SCALE);
        }

        $unresolved = $this->nonNegativeSubtract($ordered, bcadd($confirmed, $unavailable, self::SCALE));
        $backordered = $hasAnsweredEvidence ? $unresolved : '0.000000';
        $awaitingConfirmation = $hasPendingEvidence
            && bccomp($confirmed, $ordered, self::SCALE) === -1;

        return [$confirmed, $backordered, $unavailable, $awaitingConfirmation];
    }

    /**
     * @param numeric-string $ordered
     * @return array{0: numeric-string, 1: numeric-string, 2: numeric-string, 3: bool}
     */
    private function legacyHeaderQuantities(SupplierConfirmation $confirmation, string $ordered): array
    {
        return match ($confirmation->confirmation_status) {
            SupplierConfirmationStatus::Confirmed => [$ordered, '0.000000', '0.000000', false],
            SupplierConfirmationStatus::Rejected => ['0.000000', '0.000000', $ordered, false],
            default => ['0.000000', '0.000000', '0.000000', true],
        };
    }

    /** @param numeric-string $ordered @return numeric-string */
    private function capAtOrdered(string $quantity, string $ordered): string
    {
        $normalized = bcadd('0.000000', $quantity, self::SCALE);

        if (bccomp($normalized, '0.000000', self::SCALE) === -1) {
            return '0.000000';
        }

        return bccomp($normalized, $ordered, self::SCALE) === 1 ? $ordered : $normalized;
    }

    /** @param numeric-string $left @param numeric-string $right @return numeric-string */
    private function nonNegativeSubtract(string $left, string $right): string
    {
        $difference = bcsub($left, $right, self::SCALE);

        return bccomp($difference, '0.000000', self::SCALE) === -1
            ? '0.000000'
            : $difference;
    }
}
