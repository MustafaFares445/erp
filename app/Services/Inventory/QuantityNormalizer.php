<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\NormalizedQuantity;
use App\Models\ProductVariant;
use App\Models\ProductVariantUnit;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Converts a line quantity into the variant's base UOM without binary floating-point math.
 */
final class QuantityNormalizer
{
    private const int CALCULATION_SCALE = 12;

    private const int STORAGE_SCALE = 6;

    public function normalize(ProductVariant $variant, int $transactionUnitId, mixed $transactionQuantity): NormalizedQuantity
    {
        $quantity = $this->positiveDecimal($transactionQuantity, 'quantity');

        /** @var Collection<int, ProductVariantUnit> $variantUnits */
        $variantUnits = $variant->variantUnits()
            ->with('unit')
            ->where('is_active', true)
            ->get()
            ->keyBy('unit_id');

        /** @var ProductVariantUnit|null $baseUnit */
        $baseUnit = $variantUnits->first(fn (ProductVariantUnit $variantUnit): bool => $variantUnit->is_base);

        if ($variantUnits->where('is_base', true)->count() !== 1 || ! $baseUnit instanceof ProductVariantUnit) {
            throw ValidationException::withMessages([
                'unit_id' => 'The variant must have exactly one active base unit.',
            ]);
        }

        /** @var ProductVariantUnit|null $transactionUnit */
        $transactionUnit = $variantUnits->get($transactionUnitId);

        $transactionVocabularyUnit = $transactionUnit instanceof ProductVariantUnit ? $transactionUnit->unit : null;
        $baseVocabularyUnit = $baseUnit->unit;

        if (! $transactionUnit instanceof ProductVariantUnit || ! $transactionVocabularyUnit instanceof Unit || ! $transactionVocabularyUnit->is_active) {
            throw ValidationException::withMessages([
                'unit_id' => 'The selected unit is not active for this variant.',
            ]);
        }

        if (! $baseVocabularyUnit instanceof Unit || ! $baseVocabularyUnit->is_active) {
            throw ValidationException::withMessages([
                'base_unit_id' => 'The variant base unit is not active.',
            ]);
        }

        $this->assertUnitPrecision($transactionVocabularyUnit->precision, $transactionVocabularyUnit->allows_decimal, 'unit_id');
        $this->assertUnitPrecision($baseVocabularyUnit->precision, $baseVocabularyUnit->allows_decimal, 'base_unit_id');
        $this->assertWithinPrecision($quantity, $transactionVocabularyUnit->precision, 'quantity');
        $this->assertIncrement($quantity, (string) $transactionUnit->rounding_increment, $transactionVocabularyUnit->precision);

        if (! $transactionUnit->permits_cross_family_conversion && $transactionVocabularyUnit->family !== $baseVocabularyUnit->family) {
            throw ValidationException::withMessages([
                'unit_id' => 'A conversion across unit families requires an explicit variant conversion.',
            ]);
        }

        if (bccomp((string) $baseUnit->factor_to_base, '1', self::STORAGE_SCALE) !== 0) {
            throw ValidationException::withMessages([
                'base_unit_id' => 'The base unit must use a conversion factor of 1.',
            ]);
        }

        $factor = $this->positiveDecimal($transactionUnit->factor_to_base, 'factor_to_base');
        $baseQuantity = bcmul($quantity, $factor, self::CALCULATION_SCALE);

        $this->assertWithinPrecision($baseQuantity, $baseVocabularyUnit->precision, 'quantity');

        return new NormalizedQuantity(
            transactionQuantity: bcadd($quantity, '0', self::STORAGE_SCALE),
            transactionUnitId: $transactionUnitId,
            conversionFactorSnapshot: bcadd($factor, '0', self::STORAGE_SCALE),
            baseUnitId: $baseUnit->unit_id,
            baseQuantity: bcadd($baseQuantity, '0', self::STORAGE_SCALE),
        );
    }

    /** @return numeric-string */
    private function positiveDecimal(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([
                $field => 'The quantity must be supplied as an exact decimal string or integer.',
            ]);
        }

        $decimal = (string) $value;

        if (! is_numeric($decimal) || ! preg_match('/^\d+(?:\.\d{1,6})?$/', $decimal) || bccomp($decimal, '0', self::STORAGE_SCALE) !== 1) {
            throw ValidationException::withMessages([
                $field => 'The quantity must be a positive decimal with at most six decimal places.',
            ]);
        }

        return $decimal;
    }

    private function assertUnitPrecision(mixed $precision, bool $allowsDecimal, string $field): void
    {
        if (! is_int($precision) || $precision < 0 || $precision > self::STORAGE_SCALE) {
            throw ValidationException::withMessages([
                $field => 'The unit precision must be between zero and six decimal places.',
            ]);
        }

        if (! $allowsDecimal && $precision !== 0) {
            throw ValidationException::withMessages([
                $field => 'A whole-unit measure must use zero decimal places.',
            ]);
        }
    }

    /** @param numeric-string $quantity */
    private function assertWithinPrecision(string $quantity, int $precision, string $field): void
    {
        $roundedDown = bcadd($quantity, '0', $precision);

        if (bccomp($quantity, $roundedDown, self::CALCULATION_SCALE) !== 0) {
            throw ValidationException::withMessages([
                $field => 'The quantity exceeds the configured unit precision.',
            ]);
        }
    }

    /** @param numeric-string $quantity */
    private function assertIncrement(string $quantity, string $increment, int $precision): void
    {
        $increment = $this->positiveDecimal($increment, 'rounding_increment');
        $this->assertWithinPrecision($increment, $precision, 'rounding_increment');

        $multiple = bcdiv($quantity, $increment, self::CALCULATION_SCALE);

        if (bccomp($multiple, bcadd($multiple, '0', 0), self::CALCULATION_SCALE) !== 0) {
            throw ValidationException::withMessages([
                'quantity' => 'The quantity does not match the selected unit rounding increment.',
            ]);
        }
    }
}
