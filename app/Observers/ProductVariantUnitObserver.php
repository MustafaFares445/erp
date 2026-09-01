<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProductVariantUnit;
use Illuminate\Validation\ValidationException;

/**
 * Protects conversion rules from changing the meaning of quantities that have already posted.
 * A retired UOM remains queryable for historical documents; it is never removed or re-scaled.
 */
final class ProductVariantUnitObserver
{
    /** @var list<string> */
    private const array HISTORY_LOCKED_ATTRIBUTES = [
        'unit_id',
        'is_base',
        'factor_to_base',
        'rounding_increment',
        'permits_cross_family_conversion',
    ];

    public function saving(ProductVariantUnit $variantUnit): void
    {
        $factorToBase = $this->positiveDecimal($variantUnit->factor_to_base, 'factor_to_base');
        $this->positiveDecimal($variantUnit->rounding_increment, 'rounding_increment');

        if ($variantUnit->is_base && bccomp($factorToBase, '1', 6) !== 0) {
            throw ValidationException::withMessages([
                'factor_to_base' => 'The base unit must use a conversion factor of 1.',
            ]);
        }

        if (! $variantUnit->exists || ! $variantUnit->isDirty(self::HISTORY_LOCKED_ATTRIBUTES)) {
            return;
        }

        if (! $variantUnit->productVariant()->firstOrFail()->hasStockHistory()) {
            return;
        }

        throw ValidationException::withMessages([
            'variant_uom' => 'A unit conversion cannot change after this variant has stock history.',
        ]);
    }

    public function deleting(ProductVariantUnit $variantUnit): void
    {
        if (! $variantUnit->productVariant()->firstOrFail()->hasStockHistory()) {
            return;
        }

        throw ValidationException::withMessages([
            'variant_uom' => 'A unit conversion with stock history must be retired instead of deleted.',
        ]);
    }

    /** @return numeric-string */
    private function positiveDecimal(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw ValidationException::withMessages([$field => 'The value must be a positive decimal.']);
        }

        $decimal = (string) $value;

        if (! is_numeric($decimal) || ! preg_match('/^\d+(?:\.\d{1,6})?$/', $decimal) || bccomp($decimal, '0', 6) !== 1) {
            throw ValidationException::withMessages([$field => 'The value must be a positive decimal.']);
        }

        return $decimal;
    }
}
