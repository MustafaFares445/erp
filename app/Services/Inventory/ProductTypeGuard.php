<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\ProductType;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Observers\ProductVariantObserver;
use DateTimeInterface;
use DomainException;

/**
 * The one place {@see ProductType} rules are enforced.
 *
 * Every inbound and outbound path — the unified operation service, the legacy receiving
 * service, adjustments, transfers and the catalog import — delegates here rather than
 * re-deriving what a machine, an expiry material or a grain requires. Adding a type, or
 * changing what one demands, is then a change to this class and the enum, not a sweep
 * through the inventory domain.
 *
 * Rules are keyed on the *product type*, never on the variant's `track_serials` /
 * `track_expiry` columns, because {@see ProductVariantObserver} keeps those
 * columns a projection of the type. Reading the type directly means these guards stay correct
 * even for a row written before that observer existed.
 */
final readonly class ProductTypeGuard
{
    /**
     * Quantity precision, from two independent constraints that must both hold: the product
     * type (a fractional machine is meaningless) and the unit of measure (`allows_decimal`).
     *
     * @throws DomainException
     */
    public function assertQuantity(ProductVariant $variant, float $quantity, ?Unit $unit = null): void
    {
        if ($quantity <= 0) {
            throw new DomainException(__('admin.inventory.product_type.errors.non_positive_quantity'));
        }

        $type = $variant->productType();

        if ($type?->requiresWholeQuantity() === true && $this->hasFraction($quantity)) {
            throw new DomainException(__('admin.inventory.product_type.errors.whole_quantity_required', [
                'type' => $type->label(),
            ]));
        }

        $effectiveUnit = $unit ?? $variant->unit;

        if ($effectiveUnit instanceof Unit && ! $effectiveUnit->allows_decimal && $this->hasFraction($quantity)) {
            throw new DomainException(__('admin.inventory.operation.errors.invalid_quantity_precision'));
        }
    }

    /**
     * A grain is measured, so its unit of measure has to permit fractions. Enforced on the
     * catalog write path rather than at operation time, so the problem surfaces where it can
     * actually be fixed.
     *
     * @throws DomainException
     */
    public function assertUnitSuitsType(ProductVariant $variant): void
    {
        $type = $variant->productType();

        if ($type !== ProductType::Grain) {
            return;
        }

        $unit = $variant->unit;

        if ($unit instanceof Unit && ! $unit->allows_decimal) {
            throw new DomainException(__('admin.inventory.product_type.errors.grain_requires_decimal_unit'));
        }
    }

    /**
     * Grain weight completeness. Deliberately *not* called from the inventory paths: a
     * backfilled grain variant with no weight still receives and ships correctly, because
     * weight is derived reporting data. Only the catalog write surfaces demand it, which is
     * what makes the backfill non-breaking.
     *
     * @throws DomainException
     */
    public function assertWeightIsComplete(ProductVariant $variant): void
    {
        if ($variant->productType() !== ProductType::Grain) {
            return;
        }

        $netWeight = $variant->net_weight;

        if ($netWeight === null || (float) $netWeight <= 0) {
            throw new DomainException(__('admin.inventory.product_type.errors.grain_requires_net_weight'));
        }

        if ($variant->weight_unit_id === null) {
            throw new DomainException(__('admin.inventory.product_type.errors.grain_requires_weight_unit'));
        }
    }

    /**
     * An inbound line for an expiry material must name the expiry date of the lot it creates,
     * and that date must not already be in the past.
     *
     * @throws DomainException
     */
    public function assertInboundExpiry(ProductVariant $variant, ?DateTimeInterface $expiresAt): void
    {
        $type = $variant->productType();

        if ($type?->tracksExpiry() !== true) {
            if ($expiresAt !== null) {
                throw new DomainException(__('admin.inventory.product_type.errors.expiry_not_applicable'));
            }

            return;
        }

        if ($expiresAt === null) {
            throw new DomainException(__('admin.inventory.product_type.errors.expiry_required'));
        }

        if ($expiresAt->format('Y-m-d') < today()->toDateString()) {
            throw new DomainException(__('admin.inventory.product_type.errors.expiry_in_past'));
        }
    }

    /**
     * A machine receipt must register exactly one serialized unit per physical unit received.
     *
     * @throws DomainException
     */
    public function assertSerialCoverage(ProductVariant $variant, int $serialCount, float $quantity): void
    {
        $type = $variant->productType();

        if ($type?->tracksSerials() !== true) {
            if ($serialCount > 0) {
                throw new DomainException(__('admin.inventory.product_type.errors.serials_not_applicable'));
            }

            return;
        }

        if ($this->hasFraction($quantity)) {
            throw new DomainException(__('admin.inventory.product_type.errors.whole_quantity_required', [
                'type' => $type->label(),
            ]));
        }

        if ($serialCount !== (int) $quantity) {
            throw new DomainException(__('admin.inventory.product_type.errors.serial_count_mismatch', [
                'expected' => (int) $quantity,
                'given' => $serialCount,
            ]));
        }
    }

    /**
     * The serial rule for a line of the unified operation document, which carries at most one
     * `serialized_inventory_unit_id`. A machine line therefore describes exactly one physical
     * unit — two printers are two lines, which is what keeps every line traceable to a serial.
     *
     * Distinct from {@see self::assertSerialCoverage()}, which serves the legacy receipt path
     * where one item groups many serials.
     *
     * @throws DomainException
     */
    public function assertOperationLineSerial(ProductVariant $variant, ?int $serializedUnitId, float $quantity): void
    {
        $type = $variant->productType();

        if ($type?->tracksSerials() !== true) {
            if ($serializedUnitId !== null) {
                throw new DomainException(__('admin.inventory.product_type.errors.serials_not_applicable'));
            }

            return;
        }

        if ($serializedUnitId === null) {
            throw new DomainException(__('admin.inventory.product_type.errors.line_serial_required'));
        }

        if ($quantity !== 1.0) {
            throw new DomainException(__('admin.inventory.product_type.errors.line_serial_quantity'));
        }
    }

    private function hasFraction(float $quantity): bool
    {
        return fmod($quantity, 1.0) !== 0.0;
    }
}
